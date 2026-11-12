"""
Calendar, contacts, meeting invitations and directory lookup for fake_ews.py.

handle() returns None when the request is not about these items, so that
fake_ews.py falls back to its mail handling.
"""
import base64
import datetime as dt
import itertools
import re
import unicodedata
from zoneinfo import ZoneInfo

NS = {
    's': 'http://schemas.xmlsoap.org/soap/envelope/',
    'm': 'http://schemas.microsoft.com/exchange/services/2006/messages',
    't': 'http://schemas.microsoft.com/exchange/services/2006/types',
}
T = '{%s}' % NS['t']

WINDOWS_TZ = {'Romance Standard Time': 'Europe/Paris', 'W. Europe Standard Time': 'Europe/Berlin',
              'GMT Standard Time': 'Europe/London', 'UTC': 'UTC'}

COUNTER = itertools.count(5000)
EVENTS = {}
CONTACTS = {}
ME = ('Utilisateur', 'user@societe-a.fr')
# directory details: email -> (company, department, job, business phone, office)
DIRECTORY_DETAILS = {
    'marie.martin@societe-a.fr': ('Société A', 'Direction', 'Directrice commerciale', '04 93 10 10 10', 'Nice'),
    'paul.durand@societe-a.fr': ('Société A', 'Comptabilité', 'Comptable', '04 93 10 10 11', 'Nice'),
    'compta@societe-b.fr': ('Société B', 'Comptabilité', '', '04 92 20 20 20', 'Cannes'),
    'martine.leroy@societe-b.fr': ('Société B', 'Logistique', 'Responsable logistique', '04 92 20 20 21', 'Cannes'),
}
DIRECTORY = [
    ('Marie Martin', 'marie.martin@societe-a.fr'),
    ('Paul Durand', 'paul.durand@societe-a.fr'),
    ('Service Comptabilité', 'compta@societe-b.fr'),
    ('Martine Leroy', 'martine.leroy@societe-b.fr'),
]


def new_id(prefix):
    return '%s-%d' % (prefix, next(COUNTER))


def utc(d):
    return d.astimezone(dt.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def parse_dt(value, tz):
    """EWS dateTime: '...Z' is UTC, otherwise wall-clock time in the request time zone."""
    if value.endswith('Z'):
        return dt.datetime.strptime(value, '%Y-%m-%dT%H:%M:%SZ').replace(tzinfo=dt.timezone.utc)
    return dt.datetime.strptime(value[:19], '%Y-%m-%dT%H:%M:%S').replace(tzinfo=ZoneInfo(WINDOWS_TZ.get(tz, 'UTC')))


def esc(s):
    import html
    return html.escape(str(s), quote=True)


def mailbox(name, email):
    return ('<t:Mailbox><t:Name>%s</t:Name><t:EmailAddress>%s</t:EmailAddress><t:RoutingType>SMTP</t:RoutingType></t:Mailbox>'
            % (esc(name), esc(email)))


# ----------------------------------------------------------------------------
# seed data (relative to today, Europe/Paris)
# ----------------------------------------------------------------------------
PARIS = ZoneInfo('Europe/Paris')


def at(days, hour, minute=0):
    base = dt.datetime.now(PARIS).replace(hour=0, minute=0, second=0, microsecond=0)
    return (base + dt.timedelta(days=days)).replace(hour=hour, minute=minute)


def add_event(subject, start, end, all_day=False, location='', body='', organizer=ME, response='Organizer',
              attendees=(), optional=(), free_busy='Busy', ev_type='Single', series=None, reminder=15, cancelled=False, folder='F-CAL'):
    eid = new_id('EV')
    EVENTS[eid] = dict(id=eid, ck=new_id('CK'), subject=subject, start=start, end=end, all_day=all_day, location=location,
                       body=body, organizer=organizer, response=response, attendees=list(attendees), optional=list(optional),
                       free_busy=free_busy, type=ev_type, series=series, reminder=reminder, cancelled=cancelled, folder=folder)
    return eid


# ----------------------------------------------------------------------------
# other mailboxes' calendars
# ----------------------------------------------------------------------------
# email -> access: 'edit' (Editor), 'read' (Reviewer), 'freebusy' (default sharing)
SHARED_ACCESS = {
    'marie.martin@societe-a.fr': 'edit',
    'martine.leroy@societe-b.fr': 'read',
    'paul.durand@societe-a.fr': 'freebusy',
}
# busy blocks published by people who only share availability
FREEBUSY = {}


# permissions of my own calendars: folder -> list of (user, name, level, extra xml)
PERMISSIONS = {
    'F-CAL': [('Default', '', 'FreeBusyTimeOnly', ''), ('Anonymous', '', 'None', ''),
              ('paul.durand@societe-a.fr', 'Paul Durand', 'Custom',
               '<t:CanCreateItems>true</t:CanCreateItems><t:EditItems>Owned</t:EditItems><t:DeleteItems>Owned</t:DeleteItems><t:ReadItems>FullDetails</t:ReadItems>')],
    'F-CAL2': [('Default', '', 'None', ''), ('Anonymous', '', 'None', '')],
    'F-CONTACTS': [('Default', '', 'None', ''), ('Anonymous', '', 'None', ''),
                   ('martine.leroy@societe-b.fr', 'Martine Leroy', 'Custom', '<t:ReadItems>FullDetails</t:ReadItems>')],
    'F-CONTACTS2': [('Default', '', 'None', ''), ('Anonymous', '', 'None', '')],
}


def is_calendar(folder):
    return folder.startswith('F-CAL')


def permission_set_xml(folder):
    out = ''
    for user, name, level, extra in PERMISSIONS[folder]:
        uid = ('<t:DistinguishedUser>%s</t:DistinguishedUser>' % user if user in ('Default', 'Anonymous')
               else '<t:SID>S-1-5-21-%d</t:SID><t:PrimarySmtpAddress>%s</t:PrimarySmtpAddress><t:DisplayName>%s</t:DisplayName>'
               % (abs(hash(user)) % 1000, esc(user), esc(name)))
        if is_calendar(folder):
            out += '<t:CalendarPermission><t:UserId>%s</t:UserId>%s<t:CalendarPermissionLevel>%s</t:CalendarPermissionLevel></t:CalendarPermission>' % (uid, extra, level)
        else:
            out += '<t:Permission><t:UserId>%s</t:UserId>%s<t:PermissionLevel>%s</t:PermissionLevel></t:Permission>' % (uid, extra, level)
    tag = 'CalendarPermissions' if is_calendar(folder) else 'Permissions'
    return '<t:PermissionSet><t:%s>%s</t:%s></t:PermissionSet>' % (tag, out, tag)


def own_calendar_ref(ref):
    """F-CAL / F-CAL2 for a reference to one of my calendars, else None."""
    if ref.tag == T + 'FolderId' and ref.get('Id') in PERMISSIONS:
        return ref.get('Id')
    if ref.tag == T + 'DistinguishedFolderId' and ref.find('t:Mailbox', NS) is None:
        return {'calendar': 'F-CAL', 'contacts': 'F-CONTACTS'}.get(ref.get('Id'))
    return None


def calendar_folder(ref):
    """Resolve a calendar folder reference -> (folder key, error code or None)."""
    if ref.tag == T + 'FolderId':
        return ref.get('Id'), None
    mb = ref.findtext('t:Mailbox/t:EmailAddress', default=None, namespaces=NS)
    if ref.get('Id') != 'calendar':
        return None, 'ErrorFolderNotFound'
    if mb is None or mb.lower() == ME[1]:
        return 'F-CAL', None
    access = SHARED_ACCESS.get(mb.lower())
    if access is None:
        return None, 'ErrorNonExistentMailbox'
    if access == 'freebusy':
        return None, 'ErrorAccessDenied'
    return 'shared:' + mb.lower(), None


def contacts_folder(ref):
    """Resolve a contacts folder reference -> (folder key, error code or None)."""
    if ref.tag == T + 'FolderId':
        return ref.get('Id'), None
    mb = ref.findtext('t:Mailbox/t:EmailAddress', default=None, namespaces=NS)
    if ref.get('Id') != 'contacts':
        return None, 'ErrorFolderNotFound'
    if mb is None or mb.lower() == ME[1]:
        return 'F-CONTACTS', None
    access = SHARED_ACCESS.get(mb.lower())
    if access is None:
        return None, 'ErrorNonExistentMailbox'
    if access == 'freebusy':
        return None, 'ErrorAccessDenied'
    return 'shared:' + mb.lower(), None


# other address books of colleagues: folder id -> (owner, name, where: 'root' | 'contacts', access)
COLLEAGUE_BOOKS = {
    'F-MARIE-VIP': ('marie.martin@societe-a.fr', 'Clients VIP', 'contacts', 'read'),
    'F-MARIE-PART': ('marie.martin@societe-a.fr', 'Partenaires', 'root', 'edit'),
}
# colleagues whose mailbox top is visible to me ("Folder visible")
ROOT_VISIBLE = {'marie.martin@societe-a.fr'}


# public folders: id -> (parent id, name, folder class, access 'edit' | 'read')
PF_MAILBOX = 'pf-mailbox@societe-a.fr'
PUBLIC_FOLDERS = {
    'PF-SOC': ('publicfoldersroot', 'Société A', 'IPF.Note', 'read'),
    'PF-ANN': ('publicfoldersroot', 'Annonces', 'IPF.Note', 'read'),
    'PF-CLIENTS': ('PF-SOC', 'Clients communs', 'IPF.Contact', 'edit'),
    'PF-FOURN': ('PF-SOC', 'Fournisseurs agréés', 'IPF.Contact', 'read'),
    'PF-AGENDA': ('PF-SOC', 'Agenda commun', 'IPF.Appointment', 'edit'),
}
# HTTP headers of the request being handled (set by fake_ews.py)
REQUEST_HEADERS = {}


def touches_public(el):
    """Does the request address the public folders (folders or items in them)?"""
    for node in el.iter():
        i = node.get('Id')
        if i and (i == 'publicfoldersroot' or i in PUBLIC_FOLDERS or (i in CONTACTS and CONTACTS[i]['folder'] in PUBLIC_FOLDERS)):
            return True
    return False


def routed_to_public():
    h = {k.lower(): v for k, v in REQUEST_HEADERS.items()}
    return h.get('x-anchormailbox') == PF_MAILBOX and h.get('x-publicfoldermailbox') == PF_MAILBOX


def public_folder_xml(fid):
    parent, name, cls, access = PUBLIC_FOLDERS[fid]
    tag = {'IPF.Contact': 'ContactsFolder', 'IPF.Appointment': 'CalendarFolder'}.get(cls, 'Folder')
    children = sum(1 for f in PUBLIC_FOLDERS.values() if f[0] == fid)
    return ('<t:%s><t:FolderId Id="%s" ChangeKey="AQ"/><t:DisplayName>%s</t:DisplayName><t:FolderClass>%s</t:FolderClass>'
            '<t:ChildFolderCount>%d</t:ChildFolderCount>%s</t:%s>' % (tag, fid, esc(name), cls, children, rights_xml(access == 'edit'), tag))


def handle_public(op, el):
    """Requests about the public folders (already checked to be routed to the public folder mailbox)."""
    if op == 'FindFolder':
        parent = el.find('m:ParentFolderIds', NS)[0].get('Id')
        if parent != 'publicfoldersroot' and parent not in PUBLIC_FOLDERS:
            return wrap(op, [err(op, 'ErrorFolderNotFound', 'not found')])
        if el.get('Traversal') != 'Shallow':
            return wrap(op, [err(op, 'ErrorInvalidOperation', 'Deep traversal is not allowed for public folders')])
        kids = [fid for fid, f in PUBLIC_FOLDERS.items() if f[0] == parent]
        return wrap(op, [ok(op, '<m:RootFolder TotalItemsInView="%d" IncludesLastItemInRange="true"><t:Folders>%s</t:Folders></m:RootFolder>'
                            % (len(kids), ''.join(public_folder_xml(k) for k in kids)))])
    if op == 'GetFolder':
        out = []
        for r in el.find('m:FolderIds', NS):
            fid = r.get('Id')
            out.append(ok(op, '<m:Folders>%s</m:Folders>' % public_folder_xml(fid)) if fid in PUBLIC_FOLDERS
                       else err(op, 'ErrorFolderNotFound', 'not found'))
        return wrap(op, out)
    if op == 'FindItem':
        folder = el.find('m:ParentFolderIds', NS)[0].get('Id')
        if el.find('m:QueryString', NS) is not None:
            return wrap(op, [err(op, 'ErrorInvalidOperation', 'QueryString is not supported for public folders')])
        q = ''
        constant = el.find('m:Restriction//t:Constant', NS)
        if constant is not None:
            q = constant.get('Value', '').lower()
        cs = sorted((c for c in CONTACTS.values() if c['folder'] == folder), key=lambda c: c['displayName'].lower())
        if q:
            cs = [c for c in cs if q in c['displayName'].lower() or q in c.get('company', '').lower() or any(q in e.lower() for e in c.get('emails', []))]
        return wrap(op, [ok(op, '<m:RootFolder IndexedPagingOffset="%d" TotalItemsInView="%d" IncludesLastItemInRange="true"><t:Items>%s</t:Items></m:RootFolder>'
                            % (len(cs), len(cs), ''.join(contact_xml(c, False) for c in cs)))])
    if op == 'DeleteItem' and el.get('DeleteType') == 'MoveToDeletedItems':
        return wrap(op, [err(op, 'ErrorCannotDeleteObject', 'Items of public folders cannot be moved to Deleted Items')])
    return None  # GetItem, CreateItem, UpdateItem, DeleteItem: the normal handlers


def can_write(folder):
    if folder in PUBLIC_FOLDERS:
        return PUBLIC_FOLDERS[folder][3] == 'edit'
    if folder in COLLEAGUE_BOOKS:
        return COLLEAGUE_BOOKS[folder][3] == 'edit'
    return not folder.startswith('shared:') or SHARED_ACCESS.get(folder[7:]) == 'edit'


def rights_xml(write, read=True):
    w = 'true' if write else 'false'
    return ('<t:EffectiveRights><t:CreateAssociated>false</t:CreateAssociated><t:CreateContents>%s</t:CreateContents>'
            '<t:CreateHierarchy>false</t:CreateHierarchy><t:Delete>%s</t:Delete><t:Modify>%s</t:Modify><t:Read>%s</t:Read></t:EffectiveRights>'
            % (w, w, w, 'true' if read else 'false'))


def colleague_folders(parent):
    """FindFolder (Shallow) in another mailbox: its top or its « Contacts » -> response message."""
    op = 'FindFolder'
    email = parent.findtext('t:Mailbox/t:EmailAddress', default='', namespaces=NS).lower()
    if email not in SHARED_ACCESS:
        return err(op, 'ErrorNonExistentMailbox', 'No mailbox')
    where = 'root' if parent.get('Id') == 'msgfolderroot' else 'contacts'
    if where == 'root' and email not in ROOT_VISIBLE:
        return err(op, 'ErrorAccessDenied', 'Access is denied.')
    if where == 'contacts':
        _, code = contacts_folder(parent)
        if code:
            return err(op, code, code)
    folder = lambda fid, name, cls, write, read=True: (
        '<t:ContactsFolder><t:FolderId Id="%s" ChangeKey="AQ"/><t:DisplayName>%s</t:DisplayName><t:FolderClass>%s</t:FolderClass>%s</t:ContactsFolder>'
        % (fid, esc(name), cls, rights_xml(write, read)))
    out = ''
    if where == 'root':
        main = 'shared:' + email
        out += folder('CONTACTS-' + main, 'Contacts', 'IPF.Contact', can_write(main))
        out += ('<t:Folder><t:FolderId Id="INBOX-%s" ChangeKey="AQ"/><t:DisplayName>Boîte de réception</t:DisplayName>'
                '<t:FolderClass>IPF.Note</t:FolderClass>%s</t:Folder>' % (email, rights_xml(False, False)))
    else:
        out += folder('RCACHE-' + email, 'Recipient Cache', 'IPF.Contact.RecipientCache', False)
    for fid, (owner, name, w, access) in COLLEAGUE_BOOKS.items():
        if owner == email and w == where:
            out += folder(fid, name, 'IPF.Contact', access == 'edit')
    return ok(op, '<m:RootFolder TotalItemsInView="%d" IncludesLastItemInRange="true"><t:Folders>%s</t:Folders></m:RootFolder>'
              % (out.count('<t:FolderId'), out))


# ----------------------------------------------------------------------------
# recurring series: occurrences are stored in EVENTS, the rule here
# ----------------------------------------------------------------------------
SERIES = {}   # series id -> {'master': master item id, 'rule': {...}}
MASTERS = {}  # master item id -> series id
WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']


def parse_rule(rec):
    pattern = next(c for c in rec if c.tag.replace(T, '') not in ('NoEndRecurrence', 'EndDateRecurrence', 'NumberedRecurrence'))
    until = text(rec, 't:EndDateRecurrence/t:EndDate')
    count = text(rec, 't:NumberedRecurrence/t:NumberOfOccurrences')
    return dict(pattern=pattern.tag.replace(T, ''), interval=int(text(pattern, 't:Interval', '1')),
                days=text(pattern, 't:DaysOfWeek').split(), until=dt.date.fromisoformat(until[:10]) if until else None,
                count=int(count) if count else None)


def expand_rule(rule, start, end):
    dur = end - start
    n = rule['interval']
    out = []

    def add(s):
        if rule['until'] and s.date() > rule['until']:
            return False
        out.append((s, s + dur))
        return not (rule['count'] and len(out) >= rule['count']) and len(out) < 60

    if rule['pattern'] == 'WeeklyRecurrence':
        days = [WEEKDAYS.index(d) for d in (rule['days'] or [WEEKDAYS[start.weekday()]])]
        monday = start - dt.timedelta(days=start.weekday())
        for w in range(0, 200):
            for d in sorted(days):
                s = monday + dt.timedelta(days=7 * n * w + d)
                if s.date() < start.date():
                    continue
                if not add(s):
                    return out
        return out
    for i in range(0, 200):
        if rule['pattern'] == 'DailyRecurrence':
            s = start + dt.timedelta(days=n * i)
        elif rule['pattern'] == 'AbsoluteMonthlyRecurrence':
            m = start.month - 1 + n * i
            try:
                s = start.replace(year=start.year + m // 12, month=m % 12 + 1)
            except ValueError:
                continue
        else:
            try:
                s = start.replace(year=start.year + i)
            except ValueError:
                continue
        if not add(s):
            return out
    return out


def rule_xml(rule, start):
    p = rule['pattern']
    inner = '' if p == 'AbsoluteYearlyRecurrence' else '<t:Interval>%d</t:Interval>' % rule['interval']
    if p == 'WeeklyRecurrence':
        inner += '<t:DaysOfWeek>%s</t:DaysOfWeek>' % ' '.join(rule['days'] or [WEEKDAYS[start.weekday()]])
    elif p == 'AbsoluteMonthlyRecurrence':
        inner += '<t:DayOfMonth>%d</t:DayOfMonth>' % start.day
    elif p == 'AbsoluteYearlyRecurrence':
        inner += '<t:DayOfMonth>%d</t:DayOfMonth><t:Month>%s</t:Month>' % (start.day, start.strftime('%B'))
    sd = '<t:StartDate>%s</t:StartDate>' % start.date().isoformat()
    if rule['until']:
        rng = '<t:EndDateRecurrence>%s<t:EndDate>%s</t:EndDate></t:EndDateRecurrence>' % (sd, rule['until'].isoformat())
    elif rule['count']:
        rng = '<t:NumberedRecurrence>%s<t:NumberOfOccurrences>%d</t:NumberOfOccurrences></t:NumberedRecurrence>' % (sd, rule['count'])
    else:
        rng = '<t:NoEndRecurrence>%s</t:NoEndRecurrence>' % sd
    return '<t:Recurrence><t:%s>%s</t:%s>%s</t:Recurrence>' % (p, inner, p, rng)


def create_series(subject, start, end, rule, sid=None, master=None, **fields):
    sid = sid or new_id('SER')
    master = master or new_id('MS')
    SERIES[sid] = dict(master=master, rule=rule)
    MASTERS[master] = sid
    first = None
    for s, e in expand_rule(rule, start, end):
        eid = add_event(subject, s, e, ev_type='Occurrence', series=sid, **fields)
        first = first or eid
    return first


def occurrences(sid):
    return sorted((e for e in EVENTS.values() if e['series'] == sid), key=lambda e: e['start'])


def master_xml(sid):
    first = occurrences(sid)[0]
    m = dict(first, id=SERIES[sid]['master'], type='RecurringMaster')
    return event_xml(m, True, recurrence=rule_xml(SERIES[sid]['rule'], first['start']))


def seed():
    add_event('Point hebdo équipe', at(0, 9), at(0, 10), location='Salle Méditerranée',
              attendees=[('Marie Martin', 'marie.martin@societe-a.fr', 'Accept'), ('Paul Durand', 'paul.durand@societe-a.fr', 'NoResponseReceived')],
              body='Ordre du jour :\n- planning\n- clients')
    add_event('Déjeuner client Dupont', at(1, 12, 30), at(1, 14), location='Restaurant Le Port')
    add_event('Salon Informatique', at(3, 0), at(5, 0), all_day=True, location='Nice Acropolis', reminder=-1)
    add_event('Revue budget', at(2, 15), at(2, 16), organizer=('Marie Martin', 'marie.martin@societe-a.fr'), response='NoResponseReceived',
              attendees=[(ME[0], ME[1], 'NoResponseReceived')])
    add_event('Congés Lucas', at(2, 0), at(4, 0), all_day=True, folder='F-CAL2', reminder=-1)
    add_event('Visite client Antibes', at(1, 10), at(1, 12), folder='shared:marie.martin@societe-a.fr',
              organizer=('Marie Martin', 'marie.martin@societe-a.fr'), location='Antibes')
    add_event('Inventaire', at(0, 14), at(0, 17), folder='shared:martine.leroy@societe-b.fr',
              organizer=('Martine Leroy', 'martine.leroy@societe-b.fr'))
    # meetings where the calendar owner is an attendee (answer on their behalf)
    add_event('Revue fournisseurs', at(3, 11), at(3, 12), folder='shared:marie.martin@societe-a.fr',
              organizer=('Paul Durand', 'paul.durand@societe-a.fr'), response='NoResponseReceived',
              attendees=[('Marie Martin', 'marie.martin@societe-a.fr', 'NoResponseReceived')])
    add_event('Point transport', at(2, 16), at(2, 17), folder='shared:martine.leroy@societe-b.fr',
              organizer=('Paul Durand', 'paul.durand@societe-a.fr'), response='NoResponseReceived',
              attendees=[('Martine Leroy', 'martine.leroy@societe-b.fr', 'NoResponseReceived')])
    FREEBUSY['paul.durand@societe-a.fr'] = [(at(0, 11), at(0, 12), 'Busy', 'Rendez-vous fournisseur'), (at(1, 15), at(1, 16, 30), 'Tentative', None)]
    first = at(4, 18)
    create_series('Sport', first, at(4, 19), dict(pattern='WeeklyRecurrence', interval=1, days=[WEEKDAYS[first.weekday()]], until=None, count=6),
                  free_busy='Free', reminder=-1)

    def contact(given, surname, company='', email='', email2='', mobile='', business='', job='', city='', street='', postal='', notes='',
                folder='F-CONTACTS'):
        cid = new_id('CT')
        CONTACTS[cid] = dict(id=cid, kind='Contact', givenName=given, surname=surname, displayName=(given + ' ' + surname).strip() or company,
                             company=company, department='', jobTitle=job, emails=[e for e in (email, email2) if e],
                             mobilePhone=mobile, businessPhone=business, homePhone='', street=street, city=city,
                             postalCode=postal, country='France' if city else '', notes=notes, folder=folder)
    contact('Jean', 'Dupont', 'Client Exemple SA', 'jean.dupont@client-exemple.fr', mobile='06 12 34 56 78', job='Directeur', city='Nice',
            street='12 promenade des Anglais', postal='06000', notes='Client depuis 2019.')
    contact('Anne', 'Bernard', 'Cabinet Bernard', 'anne@cabinet-bernard.fr', business='04 93 00 00 00', job='Expert-comptable')
    contact('Lucas', 'Petit', '', 'lucas.petit@exemple.org')
    contact('Émilie', 'Roux', 'Roux Architectes', 'emilie@roux-archi.fr', 'emilie.roux@gmail.example', mobile='07 00 11 22 33')
    contact('', '', 'Plombier Express', 'contact@plombier-express.fr', business='04 92 11 11 11')
    gid = new_id('DL')
    CONTACTS[gid] = dict(id=gid, kind='DistributionList', displayName='Équipe projet', folder='F-CONTACTS')
    contact('', '', 'Électricité Azur', 'devis@elec-azur.example', business='04 93 55 55 55', folder='F-CONTACTS2')
    contact('', '', 'Papeterie du Port', 'commandes@papeterie-port.example', folder='F-CONTACTS2')
    contact('Hugo', 'Blanc', 'Client Riviera', 'hugo@client-riviera.example', mobile='06 77 77 77 77', folder='shared:marie.martin@societe-a.fr')
    contact('Laura', 'Costa', 'Hôtel Bellevue', 'laura@hotel-bellevue.example', job='Directrice', folder='F-MARIE-VIP')
    contact('', '', 'Agence Horizon', 'contact@agence-horizon.example', folder='F-MARIE-PART')
    contact('', '', 'Imprimerie Azur', 'devis@imprimerie-azur.example', business='04 93 66 66 66', folder='PF-CLIENTS')
    contact('Nadia', 'Roux', 'Cabinet Roux', 'nadia@cabinet-roux.example', job='Expert-comptable', folder='PF-CLIENTS')
    contact('', '', 'Location Matériel Sud', 'agence@location-sud.example', folder='PF-FOURN')
    contact('Sophie', 'Girard', 'Transports Girard', 'sophie@transports-girard.example', job='Gérante', folder='shared:martine.leroy@societe-b.fr')
    contact('Karim', 'Benali', 'Entrepôt Sud', 'karim@entrepot-sud.example', folder='shared:martine.leroy@societe-b.fr')


seed()


# ----------------------------------------------------------------------------
def wrap(op, msgs):
    return ('<m:%sResponse xmlns:m="%s" xmlns:t="%s"><m:ResponseMessages>%s</m:ResponseMessages></m:%sResponse>'
            % (op, NS['m'], NS['t'], ''.join(msgs), op))


def ok(op, inner=''):
    return '<m:%sResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode>%s</m:%sResponseMessage>' % (op, inner, op)


def err(op, code, text):
    return ('<m:%sResponseMessage ResponseClass="Error"><m:MessageText>%s</m:MessageText><m:ResponseCode>%s</m:ResponseCode>'
            '<m:DescriptiveLinkKey>0</m:DescriptiveLinkKey></m:%sResponseMessage>' % (op, esc(text), code, op))


def attendees_xml(tag, lst):
    if not lst:
        return ''
    return '<t:%s>%s</t:%s>' % (tag, ''.join('<t:Attendee>%s<t:ResponseType>%s</t:ResponseType></t:Attendee>' % (mailbox(n, e), r) for n, e, r in lst), tag)


def event_xml(e, detail, recurrence=''):
    is_meeting = bool(e['attendees'] or e['optional']) or e['organizer'] != ME
    x = '<t:CalendarItem><t:ItemId Id="%s" ChangeKey="%s"/><t:Subject>%s</t:Subject>' % (e['id'], e['ck'], esc(e['subject']))
    if detail:
        x += '<t:Body BodyType="Text">%s</t:Body>' % esc(e['body'])
        x += '<t:ReminderIsSet>%s</t:ReminderIsSet>' % ('true' if e['reminder'] >= 0 else 'false')
        if e['reminder'] >= 0:
            x += '<t:ReminderMinutesBeforeStart>%d</t:ReminderMinutesBeforeStart>' % e['reminder']
    x += ('<t:Start>%s</t:Start><t:End>%s</t:End><t:IsAllDayEvent>%s</t:IsAllDayEvent><t:LegacyFreeBusyStatus>%s</t:LegacyFreeBusyStatus>'
          % (utc(e['start']), utc(e['end']), 'true' if e['all_day'] else 'false', e['free_busy']))
    if e['location']:
        x += '<t:Location>%s</t:Location>' % esc(e['location'])
    x += ('<t:IsMeeting>%s</t:IsMeeting><t:IsCancelled>%s</t:IsCancelled><t:IsRecurring>%s</t:IsRecurring><t:CalendarItemType>%s</t:CalendarItemType>'
          '<t:MyResponseType>%s</t:MyResponseType><t:Organizer>%s</t:Organizer>'
          % ('true' if is_meeting else 'false', 'true' if e['cancelled'] else 'false', 'true' if e['series'] else 'false',
             e['type'], e['response'], mailbox(*e['organizer'])))
    if detail:
        x += attendees_xml('RequiredAttendees', e['attendees']) + attendees_xml('OptionalAttendees', e['optional'])
    return x + recurrence + '</t:CalendarItem>'


def contact_xml(c, detail):
    if c['kind'] == 'DistributionList':
        return '<t:DistributionList><t:ItemId Id="%s"/><t:DisplayName>%s</t:DisplayName></t:DistributionList>' % (c['id'], esc(c['displayName']))
    x = '<t:Contact><t:ItemId Id="%s"/>' % c['id']
    if detail and c['notes']:
        x += '<t:Body BodyType="Text">%s</t:Body>' % esc(c['notes'])
    x += '<t:DisplayName>%s</t:DisplayName>' % esc(c['displayName'])
    if detail and c['givenName']:
        x += '<t:GivenName>%s</t:GivenName>' % esc(c['givenName'])
    if c['company']:
        x += '<t:CompanyName>%s</t:CompanyName>' % esc(c['company'])
    if c['emails']:
        emails = c['emails'] if detail else c['emails'][:1]
        x += '<t:EmailAddresses>%s</t:EmailAddresses>' % ''.join('<t:Entry Key="EmailAddress%d">%s</t:Entry>' % (i + 1, esc(e)) for i, e in enumerate(emails))
    if detail and any(c[k] for k in ('street', 'city', 'postalCode', 'country')):
        x += '<t:PhysicalAddresses><t:Entry Key="Business">%s</t:Entry></t:PhysicalAddresses>' % ''.join(
            '<t:%s>%s</t:%s>' % (el, esc(c[k]), el) for k, el in (('street', 'Street'), ('city', 'City'), ('country', 'CountryOrRegion'), ('postalCode', 'PostalCode')) if c[k])
    phones = [(k, el) for k, el in (('mobilePhone', 'MobilePhone'), ('businessPhone', 'BusinessPhone'), ('homePhone', 'HomePhone')) if c[k]]
    if phones:
        x += '<t:PhoneNumbers>%s</t:PhoneNumbers>' % ''.join('<t:Entry Key="%s">%s</t:Entry>' % (el, esc(c[k])) for k, el in phones)
    if detail and c['department']:
        x += '<t:Department>%s</t:Department>' % esc(c['department'])
    if c['jobTitle']:
        x += '<t:JobTitle>%s</t:JobTitle>' % esc(c['jobTitle'])
    if detail and c['surname']:
        x += '<t:Surname>%s</t:Surname>' % esc(c['surname'])
    return x + '</t:Contact>'


def text(el, path, default=''):
    v = el.findtext(path, default=None, namespaces=NS)
    return default if v is None else v


def read_attendees(el, tag):
    out = []
    for a in el.findall('t:%s/t:Attendee' % tag, NS):
        e = text(a, 't:Mailbox/t:EmailAddress')
        name = next((n for n, m in DIRECTORY if m == e), e.split('@')[0])
        out.append((name, e, 'NoResponseReceived'))
    return out


def contact_from(el, existing=None):
    c = existing or dict(kind='Contact', givenName='', surname='', displayName='', company='', department='', jobTitle='', emails=[],
                         mobilePhone='', businessPhone='', homePhone='', street='', city='', postalCode='', country='', notes='')
    return c


def apply_contact_fields(c, ct):
    """ct: a t:Contact element (create, or the value of one SetItemField)."""
    simple = {'DisplayName': 'displayName', 'GivenName': 'givenName', 'Surname': 'surname', 'CompanyName': 'company',
              'Department': 'department', 'JobTitle': 'jobTitle'}
    for el, key in simple.items():
        v = ct.find('t:' + el, NS)
        if v is not None:
            c[key] = v.text or ''
    b = ct.find('t:Body', NS)
    if b is not None:
        c['notes'] = b.text or ''
    for entry in ct.findall('t:EmailAddresses/t:Entry', NS):
        i = int(entry.get('Key')[-1]) - 1
        while len(c['emails']) <= i:
            c['emails'].append('')
        c['emails'][i] = entry.text or ''
    for entry in ct.findall('t:PhoneNumbers/t:Entry', NS):
        key = {'MobilePhone': 'mobilePhone', 'BusinessPhone': 'businessPhone', 'HomePhone': 'homePhone'}[entry.get('Key')]
        c[key] = entry.text or ''
    for entry in ct.findall('t:PhysicalAddresses/t:Entry', NS):
        for el, key in (('Street', 'street'), ('City', 'city'), ('CountryOrRegion', 'country'), ('PostalCode', 'postalCode')):
            v = entry.find('t:' + el, NS)
            if v is not None:
                c[key] = v.text or ''


def delete_contact_field(c, path):
    uri, index = path.get('FieldURI'), path.get('FieldIndex')
    mapping = {'contacts:DisplayName': 'displayName', 'contacts:GivenName': 'givenName', 'contacts:Surname': 'surname',
               'contacts:CompanyName': 'company', 'contacts:Department': 'department', 'contacts:JobTitle': 'jobTitle', 'item:Body': 'notes',
               'contacts:PhysicalAddress:Street': 'street', 'contacts:PhysicalAddress:City': 'city',
               'contacts:PhysicalAddress:CountryOrRegion': 'country', 'contacts:PhysicalAddress:PostalCode': 'postalCode'}
    if uri == 'contacts:EmailAddress':
        i = int(index[-1]) - 1
        if i < len(c['emails']):
            c['emails'][i] = ''
    elif uri == 'contacts:PhoneNumber':
        c[{'MobilePhone': 'mobilePhone', 'BusinessPhone': 'businessPhone', 'HomePhone': 'homePhone'}[index]] = ''
    elif uri in mapping:
        c[mapping[uri]] = ''


# ----------------------------------------------------------------------------
# automatic replies per mailbox
OOF = {}


def handle_oof(op, el, user):
    mailbox = el.findtext('t:Mailbox/t:Address', default='', namespaces=NS).lower()
    resp = op.replace('Request', 'Response')
    if mailbox != user.lower():
        return ('<m:%s xmlns:m="%s"><m:ResponseMessage ResponseClass="Error"><m:MessageText>Access is denied.</m:MessageText>'
                '<m:ResponseCode>ErrorAccessDenied</m:ResponseCode></m:ResponseMessage></m:%s>' % (resp, NS['m'], resp))
    if op == 'SetUserOofSettingsRequest':
        s = el.find('t:UserOofSettings', NS)
        OOF[mailbox] = dict(
            state=s.findtext('t:OofState', namespaces=NS), audience=s.findtext('t:ExternalAudience', namespaces=NS),
            start=s.findtext('t:Duration/t:StartTime', default='', namespaces=NS), end=s.findtext('t:Duration/t:EndTime', default='', namespaces=NS),
            internal=s.findtext('t:InternalReply/t:Message', default='', namespaces=NS), external=s.findtext('t:ExternalReply/t:Message', default='', namespaces=NS))
        return '<m:%s xmlns:m="%s"><m:ResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode></m:ResponseMessage></m:%s>' % (resp, NS['m'], resp)
    o = OOF.get(mailbox, dict(state='Disabled', audience='All', start='2026-01-01T00:00:00Z', end='2026-01-02T00:00:00Z', internal='', external=''))
    return ('<m:%s xmlns:m="%s" xmlns:t="%s"><m:ResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode></m:ResponseMessage>'
            '<t:OofSettings><t:OofState>%s</t:OofState><t:ExternalAudience>%s</t:ExternalAudience>'
            '<t:Duration><t:StartTime>%s</t:StartTime><t:EndTime>%s</t:EndTime></t:Duration>'
            '<t:InternalReply><t:Message>%s</t:Message></t:InternalReply><t:ExternalReply><t:Message>%s</t:Message></t:ExternalReply></t:OofSettings>'
            '<m:AllowExternalOof>All</m:AllowExternalOof></m:%s>'
            % (resp, NS['m'], NS['t'], o['state'], o['audience'], o['start'] or '2026-01-01T00:00:00Z', o['end'] or '2026-01-02T00:00:00Z',
               esc(o['internal']), esc(o['external']), resp))


def handle(op, el, user, tz, messages, move_message):
    """messages / move_message give access to the mail store of fake_ews.py (meeting requests)."""
    if op in ('GetUserOofSettingsRequest', 'SetUserOofSettingsRequest'):
        return handle_oof(op, el, user)
    # Exchange 2013+: public folder requests must be routed to the public folder mailbox, and only them
    public = touches_public(el)
    if public != routed_to_public():
        return wrap(op, [err(op, 'ErrorFolderNotFound' if public else 'ErrorAccessDenied',
                             'request %s routed to the public folder mailbox' % ('not' if public else 'wrongly'))])
    if public:
        r = handle_public(op, el)
        if r is not None:
            return r

    if op == 'GetFolder' and any(f.get('FieldURI') == 'folder:PermissionSet' for f in el.findall('m:FolderShape/t:AdditionalProperties/t:FieldURI', NS)):
        out = []
        for r in el.find('m:FolderIds', NS):
            folder = own_calendar_ref(r)
            if folder is None:
                out.append(err(op, 'ErrorAccessDenied', 'Access is denied.'))
            else:
                tag = 'CalendarFolder' if is_calendar(folder) else 'ContactsFolder'
                out.append(ok(op, '<m:Folders><t:%s><t:FolderId Id="%s" ChangeKey="AQ"/>%s</t:%s></m:Folders>'
                              % (tag, folder, permission_set_xml(folder), tag)))
        return wrap(op, out)

    if op == 'UpdateFolder':
        out = []
        for ch in el.findall('m:FolderChanges/t:FolderChange', NS):
            folder = own_calendar_ref(ch[0])
            if folder is None:
                out.append(err(op, 'ErrorAccessDenied', 'Access is denied.'))
                continue
            cal = is_calendar(folder)
            perms = ch.findall('t:Updates/t:SetFolderField/t:CalendarFolder/t:PermissionSet/t:CalendarPermissions/t:CalendarPermission' if cal
                               else 't:Updates/t:SetFolderField/t:ContactsFolder/t:PermissionSet/t:Permissions/t:Permission', NS)
            level_tag = 'CalendarPermissionLevel' if cal else 'PermissionLevel'
            new = []
            bad = None
            for p in perms:
                user = text(p, 't:UserId/t:DistinguishedUser') or text(p, 't:UserId/t:PrimarySmtpAddress').lower()
                level = text(p, 't:' + level_tag)
                if user not in ('Default', 'Anonymous') and user not in [e for _, e in DIRECTORY]:
                    bad = 'ErrorInvalidUserInfo'
                    break
                extra = ''.join('<t:%s>%s</t:%s>' % (c.tag.replace(T, ''), esc(c.text or ''), c.tag.replace(T, ''))
                                for c in p if c.tag.replace(T, '') not in ('UserId', level_tag))
                name = next((n for n, e in DIRECTORY if e == user), '')
                new.append((user, name, level, extra))
            if bad is None and not any(u == 'Default' for u, _, _, _ in new):
                bad = 'ErrorInvalidPermissionSettings'
            if bad:
                out.append(err(op, bad, bad))
                continue
            PERMISSIONS[folder] = new
            tag = 'CalendarFolder' if cal else 'ContactsFolder'
            out.append(ok(op, '<m:Folders><t:%s><t:FolderId Id="%s" ChangeKey="AQ"/></t:%s></m:Folders>' % (tag, folder, tag)))
        return wrap(op, out)

    if op == 'FindFolder':
        parents = list(el.find('m:ParentFolderIds', NS))
        if not any(p.find('t:Mailbox', NS) is not None for p in parents):
            return None
        return wrap(op, [colleague_folders(p) for p in parents])

    if op == 'GetFolder':
        refs = list(el.find('m:FolderIds', NS))
        if not any(r.get('Id') in ('calendar', 'contacts') for r in refs):
            return None
        out = []
        for r in refs:
            if r.get('Id') not in ('calendar', 'contacts'):
                return None  # mixed request: let the mail handler answer
            kind = r.get('Id')
            folder, code = calendar_folder(r) if kind == 'calendar' else contacts_folder(r)
            if code:
                out.append(err(op, code, code))
                continue
            write = 'true' if can_write(folder) else 'false'
            rights = ('<t:EffectiveRights><t:CreateAssociated>false</t:CreateAssociated><t:CreateContents>%s</t:CreateContents>'
                      '<t:CreateHierarchy>false</t:CreateHierarchy><t:Delete>%s</t:Delete><t:Modify>%s</t:Modify><t:Read>true</t:Read></t:EffectiveRights>'
                      % (write, write, write))
            tag = 'CalendarFolder' if kind == 'calendar' else 'ContactsFolder'
            fid = folder if folder in ('F-CAL', 'F-CONTACTS') else kind.upper() + '-' + folder
            out.append(ok(op, '<m:Folders><t:%s><t:FolderId Id="%s" ChangeKey="AQ"/>%s</t:%s></m:Folders>' % (tag, fid, rights, tag)))
        return wrap(op, out)

    if op == 'GetUserAvailabilityRequest':
        email = text(el, 'm:MailboxDataArray/t:MailboxData/t:Email/t:Address').lower()
        win = el.find('t:FreeBusyViewOptions/t:TimeWindow', NS)
        start = parse_dt(text(win, 't:StartTime')[:19] + 'Z', 'UTC')
        end = parse_dt(text(win, 't:EndTime')[:19] + 'Z', 'UTC')
        if email not in SHARED_ACCESS:
            msg = '<ResponseMessage ResponseClass="Error"><MessageText>Not found</MessageText><ResponseCode>ErrorMailRecipientNotFound</ResponseCode></ResponseMessage>'
            events = ''
        else:
            msg = '<ResponseMessage ResponseClass="Success"><ResponseCode>NoError</ResponseCode></ResponseMessage>'
            blocks = list(FREEBUSY.get(email, []))
            folder = 'shared:' + email
            blocks += [(e['start'], e['end'], e['free_busy'], e['subject']) for e in EVENTS.values() if e['folder'] == folder]
            fmt = lambda d: d.astimezone(dt.timezone.utc).strftime('%Y-%m-%dT%H:%M:%S')
            events = ''.join('<CalendarEvent><StartTime>%s</StartTime><EndTime>%s</EndTime><BusyType>%s</BusyType>%s</CalendarEvent>'
                             % (fmt(s_), fmt(e_), b, '<CalendarEventDetails><Subject>%s</Subject></CalendarEventDetails>' % esc(subj) if subj else '')
                             for s_, e_, b, subj in blocks if s_ < end and e_ > start)
        return ('<GetUserAvailabilityResponse xmlns="%s"><FreeBusyResponseArray><FreeBusyResponse>%s<FreeBusyView>'
                '<FreeBusyViewType xmlns="%s">Detailed</FreeBusyViewType><CalendarEventArray xmlns="%s">%s</CalendarEventArray>'
                '</FreeBusyView></FreeBusyResponse></FreeBusyResponseArray></GetUserAvailabilityResponse>' % (NS['m'], msg, NS['t'], NS['t'], events))

    if op == 'FindItem':
        parent = el.find('m:ParentFolderIds', NS)[0]
        pid = parent.get('Id')
        if pid in ('calendar', 'F-CAL', 'F-CAL2') or (el.find('m:CalendarView', NS) is not None):
            folder, code = calendar_folder(parent)
            if code:
                return wrap(op, [err(op, code, code)])
            view = el.find('m:CalendarView', NS)
            start, end = parse_dt(view.get('StartDate'), tz), parse_dt(view.get('EndDate'), tz)
            evs = sorted((e for e in EVENTS.values() if e['folder'] == folder and e['start'] < end and e['end'] > start), key=lambda e: e['start'])
            return wrap(op, [ok(op, '<m:RootFolder TotalItemsInView="%d" IncludesLastItemInRange="true"><t:Items>%s</t:Items></m:RootFolder>'
                                % (len(evs), ''.join(event_xml(e, False) for e in evs)))])
        if pid in ('contacts', 'F-CONTACTS', 'F-CONTACTS2') or pid in COLLEAGUE_BOOKS or pid in PUBLIC_FOLDERS:
            folder, code = contacts_folder(parent)
            if code:
                return wrap(op, [err(op, code, code)])
            view = el.find('m:IndexedPageItemView', NS)
            offset, limit = int(view.get('Offset', 0)), int(view.get('MaxEntriesReturned', 100))
            q = text(el, 'm:QueryString').lower()
            # Exchange sorts accent-insensitively (É with E)
            cs = sorted((c for c in CONTACTS.values() if c['folder'] == folder),
                        key=lambda c: unicodedata.normalize('NFD', c['displayName'].lower()).encode('ascii', 'ignore'))
            if q:
                cs = [c for c in cs if q in c['displayName'].lower() or q in c.get('company', '').lower()
                      or any(q in e.lower() for e in c.get('emails', []))]
            page = cs[offset:offset + limit]
            return wrap(op, [ok(op, '<m:RootFolder IndexedPagingOffset="%d" TotalItemsInView="%d" IncludesLastItemInRange="%s"><t:Items>%s</t:Items></m:RootFolder>'
                                % (offset + len(page), len(cs), 'true' if offset + limit >= len(cs) else 'false', ''.join(contact_xml(c, False) for c in page)))])
        return None

    if op == 'GetItem':
        refs = list(el.find('m:ItemIds', NS))
        if refs and refs[0].tag == T + 'RecurringMasterItemId':
            occ = EVENTS.get(refs[0].get('OccurrenceId'))
            if not occ or not occ['series']:
                return wrap(op, [err(op, 'ErrorItemNotFound', 'not found')])
            return wrap(op, [ok(op, '<m:Items>%s</m:Items>' % master_xml(occ['series']))])
        ids = [i.get('Id') for i in refs]
        fields = [f.get('FieldURI') for f in el.findall('m:ItemShape/t:AdditionalProperties/t:FieldURI', NS)]
        if all(i in EVENTS for i in ids):
            return wrap(op, [ok(op, '<m:Items>%s</m:Items>' % event_xml(EVENTS[i], True)) for i in ids])
        if all(i in CONTACTS for i in ids):
            return wrap(op, [ok(op, '<m:Items>%s</m:Items>' % contact_xml(CONTACTS[i], True)) for i in ids])
        if len(ids) == 1 and 'calendar:Start' in fields and ids[0] in messages and messages[ids[0]].get('kind') in ('MeetingRequest', 'MeetingCancellation'):
            m = messages[ids[0]]
            mt = m['meeting']
            rep_xml = ''
            if mt.get('representing'):
                rep_xml = '<t:ReceivedRepresenting>%s</t:ReceivedRepresenting>' % mailbox(*mt['representing'])
            xml = ('<m:Items><t:%s><t:ItemId Id="%s" ChangeKey="%s"/>' % (m['kind'], m['id'], m['ck'])
                   + rep_xml
                   + '<t:Start>%s</t:Start><t:End>%s</t:End><t:IsAllDayEvent>false</t:IsAllDayEvent><t:Location>%s</t:Location>'
                     '<t:MyResponseType>%s</t:MyResponseType></t:%s></m:Items>'
                   % (utc(mt['start']), utc(mt['end']), esc(mt['location']), mt['response'], m['kind']))
            return wrap(op, [ok(op, xml)])
        return None

    if op == 'CreateItem':
        items = list(el.find('m:Items', NS))
        tag = items[0].tag.replace(T, '') if items else ''
        if tag == 'CalendarItem':
            out = []
            target = el.find('m:SavedItemFolderId', NS)
            folder, code = calendar_folder(target[0]) if target is not None else ('F-CAL', None)
            if code or not can_write(folder):
                return wrap(op, [err(op, code or 'ErrorAccessDenied', 'Access is denied.')])
            for it in items:
                start, end = parse_dt(text(it, 't:Start'), tz), parse_dt(text(it, 't:End'), tz)
                if end < start:
                    out.append(err(op, 'ErrorCalendarEndDateIsEarlierThanStartDate', 'End before start'))
                    continue
                attendees, optional = read_attendees(it, 'RequiredAttendees'), read_attendees(it, 'OptionalAttendees')
                common = dict(location=text(it, 't:Location'), body=text(it, 't:Body'), all_day=text(it, 't:IsAllDayEvent') == 'true',
                              attendees=attendees, optional=optional, free_busy=text(it, 't:LegacyFreeBusyStatus', 'Busy'),
                              reminder=int(text(it, 't:ReminderMinutesBeforeStart', '-1')) if text(it, 't:ReminderIsSet') == 'true' else -1)
                rec = it.find('t:Recurrence', NS)
                if rec is not None:
                    first = create_series(text(it, 't:Subject'), start, end, parse_rule(rec), folder=folder, **common)
                    out.append(ok(op, '<m:Items><t:CalendarItem><t:ItemId Id="%s" ChangeKey="CK"/></t:CalendarItem></m:Items>' % first))
                else:
                    eid = add_event(text(it, 't:Subject'), start, end, folder=folder, **common)
                    out.append(ok(op, '<m:Items><t:CalendarItem><t:ItemId Id="%s" ChangeKey="%s"/></t:CalendarItem></m:Items>' % (eid, EVENTS[eid]['ck'])))
            return wrap(op, out)
        if tag == 'Contact':
            out = []
            target = el.find('m:SavedItemFolderId', NS)
            folder, code = contacts_folder(target[0]) if target is not None else ('F-CONTACTS', None)
            if code or not can_write(folder):
                return wrap(op, [err(op, code or 'ErrorAccessDenied', 'Access is denied.')])
            for it in items:
                c = contact_from(it)
                c['folder'] = folder
                c['id'] = new_id('CT')
                apply_contact_fields(c, it)
                c['emails'] = [e for e in c['emails'] if e]
                CONTACTS[c['id']] = c
                out.append(ok(op, '<m:Items><t:Contact><t:ItemId Id="%s" ChangeKey="CK"/></t:Contact></m:Items>' % c['id']))
            return wrap(op, out)
        if tag in ('AcceptItem', 'TentativelyAcceptItem', 'DeclineItem'):
            response = {'AcceptItem': 'Accept', 'TentativelyAcceptItem': 'Tentative', 'DeclineItem': 'Decline'}[tag]
            ref = items[0].find('t:ReferenceItemId', NS).get('Id')
            if ref in EVENTS:
                e = EVENTS[ref]
                if not can_write(e['folder']):
                    return wrap(op, [err(op, 'ErrorAccessDenied', 'Access is denied.')])
                if e['organizer'] == ME and e['folder'] == 'F-CAL':
                    return wrap(op, [err(op, 'ErrorCalendarIsOrganizerForAccept', 'You are the organizer')])
                e['response'] = response
                if response == 'Decline':
                    del EVENTS[ref]
                return wrap(op, [ok(op, '<m:Items/>')])
            if ref in messages and messages[ref].get('kind') == 'MeetingRequest':
                m = messages[ref]
                m['meeting']['response'] = response
                linked = EVENTS.get(m['meeting']['event'])
                if linked:
                    linked['response'] = response
                    if response == 'Decline':
                        del EVENTS[linked['id']]
                move_message(ref, 'F-DELETED')
                return wrap(op, [ok(op, '<m:Items/>')])
            return wrap(op, [err(op, 'ErrorItemNotFound', 'not found')])
        return None

    if op == 'UpdateItem':
        changes = list(el.find('m:ItemChanges', NS))
        ids = [ch.find('t:ItemId', NS).get('Id') for ch in changes]
        if any(i in EVENTS and not can_write(EVENTS[i]['folder']) for i in ids):
            return wrap(op, [err(op, 'ErrorAccessDenied', 'Access is denied.')])
        if ids and all(i in EVENTS or i in MASTERS for i in ids):
            if el.get('SendMeetingInvitationsOrCancellations') is None:
                return wrap(op, [err(op, 'ErrorSendMeetingInvitationsOrCancellationsRequired', 'required for calendar items')])
            for ch in changes:
                iid = ch.find('t:ItemId', NS).get('Id')
                sid = MASTERS.get(iid)
                # a series is edited on a copy of its first occurrence, then regenerated
                e = dict(occurrences(sid)[0]) if sid else EVENTS[iid]
                rule_change = 'keep'
                for upd in ch.find('t:Updates', NS):
                    if upd.find('t:FieldURI', NS).get('FieldURI') == 'calendar:Recurrence':
                        rule_change = 'delete' if upd.tag == T + 'DeleteItemField' else parse_rule(upd.find('t:CalendarItem/t:Recurrence', NS))
                        continue
                    uri = upd.find('t:FieldURI', NS).get('FieldURI')
                    if upd.tag == T + 'DeleteItemField':
                        if uri == 'calendar:Location':
                            e['location'] = ''
                        elif uri == 'calendar:RequiredAttendees':
                            e['attendees'] = []
                        elif uri == 'calendar:OptionalAttendees':
                            e['optional'] = []
                        continue
                    ci = upd.find('t:CalendarItem', NS)
                    if uri == 'item:Subject':
                        e['subject'] = text(ci, 't:Subject')
                    elif uri == 'item:Body':
                        e['body'] = text(ci, 't:Body')
                    elif uri == 'calendar:Start':
                        e['start'] = parse_dt(text(ci, 't:Start'), tz)
                    elif uri == 'calendar:End':
                        e['end'] = parse_dt(text(ci, 't:End'), tz)
                    elif uri == 'calendar:IsAllDayEvent':
                        e['all_day'] = text(ci, 't:IsAllDayEvent') == 'true'
                    elif uri == 'calendar:Location':
                        e['location'] = text(ci, 't:Location')
                    elif uri == 'calendar:LegacyFreeBusyStatus':
                        e['free_busy'] = text(ci, 't:LegacyFreeBusyStatus')
                    elif uri == 'calendar:RequiredAttendees':
                        e['attendees'] = read_attendees(ci, 'RequiredAttendees')
                    elif uri == 'calendar:OptionalAttendees':
                        e['optional'] = read_attendees(ci, 'OptionalAttendees')
                    elif uri == 'item:ReminderIsSet' and text(ci, 't:ReminderIsSet') == 'false':
                        e['reminder'] = -1
                    elif uri == 'item:ReminderMinutesBeforeStart':
                        e['reminder'] = int(text(ci, 't:ReminderMinutesBeforeStart'))
                if e['end'] < e['start']:
                    return wrap(op, [err(op, 'ErrorCalendarEndDateIsEarlierThanStartDate', 'End before start')])
                e['ck'] = new_id('CK')
                if sid:
                    for k in [k for k, x in EVENTS.items() if x['series'] == sid]:
                        del EVENTS[k]
                    fields = dict(location=e['location'], body=e['body'], all_day=e['all_day'], organizer=e['organizer'], response=e['response'],
                                  attendees=e['attendees'], optional=e['optional'], free_busy=e['free_busy'], reminder=e['reminder'],
                                  folder=e['folder'])
                    if rule_change == 'delete':
                        del SERIES[sid], MASTERS[iid]
                        add_event(e['subject'], e['start'], e['end'], **fields)
                    else:
                        rule = SERIES[sid]['rule'] if rule_change == 'keep' else rule_change
                        create_series(e['subject'], e['start'], e['end'], rule, sid=sid, master=iid, **fields)
            return wrap(op, [ok(op, '<m:Items/><m:ConflictResults><t:Count>0</t:Count></m:ConflictResults>') for _ in ids])
        if any(i in CONTACTS and not can_write(CONTACTS[i]['folder']) for i in ids):
            return wrap(op, [err(op, 'ErrorAccessDenied', 'Access is denied.')])
        if ids and all(i in CONTACTS for i in ids):
            for ch in changes:
                c = CONTACTS[ch.find('t:ItemId', NS).get('Id')]
                for upd in ch.find('t:Updates', NS):
                    if upd.tag == T + 'DeleteItemField':
                        delete_contact_field(c, upd[0])
                    else:
                        apply_contact_fields(c, upd.find('t:Contact', NS))
                c['emails'] = [e for e in c['emails'] if e]
            return wrap(op, [ok(op, '<m:Items/><m:ConflictResults><t:Count>0</t:Count></m:ConflictResults>') for _ in ids])
        return None

    if op == 'DeleteItem':
        targets = list(el.find('m:ItemIds', NS))
        if any(t.get('Id') in EVENTS and not can_write(EVENTS[t.get('Id')]['folder']) for t in targets):
            return wrap(op, [err(op, 'ErrorAccessDenied', 'Access is denied.')])
        if targets and all((t.tag == T + 'RecurringMasterItemId' and t.get('OccurrenceId') in EVENTS) or t.get('Id') in EVENTS for t in targets):
            if el.get('SendMeetingCancellations') is None:
                return wrap(op, [err(op, 'ErrorSendMeetingCancellationsRequired', 'required for calendar items')])
            for t in targets:
                if t.tag == T + 'RecurringMasterItemId':
                    series = EVENTS[t.get('OccurrenceId')]['series']
                    for k in [k for k, e in EVENTS.items() if e['series'] == series]:
                        del EVENTS[k]
                else:
                    del EVENTS[t.get('Id')]
            return wrap(op, [ok(op) for _ in targets])
        if any(t.get('Id') in CONTACTS and not can_write(CONTACTS[t.get('Id')]['folder']) for t in targets):
            return wrap(op, [err(op, 'ErrorAccessDenied', 'Access is denied.')])
        if targets and all(t.get('Id') in CONTACTS for t in targets):
            for t in targets:
                del CONTACTS[t.get('Id')]
            return wrap(op, [ok(op) for _ in targets])
        return None

    if op in ('GetDelegate', 'AddDelegate', 'UpdateDelegate', 'RemoveDelegate'):
        return handle_delegates(op, el, user)

    if op == 'ResolveNames':
        q = text(el, 'm:UnresolvedEntry').lower()
        scope = el.get('SearchScope', 'ActiveDirectoryContacts')
        full = el.get('ReturnFullContactData') == 'true'
        found = []
        # personal contacts (default folder) unless only the directory is searched
        for c in (CONTACTS.values() if scope != 'ActiveDirectory' else []):
            if c['folder'] != 'F-CONTACTS':
                continue
            if c['kind'] == 'Contact' and c['emails'] and (q in c['displayName'].lower() or any(q in e.lower() for e in c['emails'])):
                found.append((c['displayName'], c['emails'][0], 'Contact'))
        for n, e in DIRECTORY:
            if q in n.lower() or q in e.lower():
                found.append((n, e, 'Mailbox'))
        if not found:
            return wrap(op, [err(op, 'ErrorNameResolutionNoResults', 'No results were found.')])
        def details(n, e, k):
            if not full or k != 'Mailbox':
                return ''
            company, dept, job, phone, office = DIRECTORY_DETAILS.get(e, ('', '', '', '', ''))
            given, _, surname = n.partition(' ')
            return ('<t:Contact><t:DisplayName>%s</t:DisplayName><t:GivenName>%s</t:GivenName><t:CompanyName>%s</t:CompanyName>'
                    '<t:EmailAddresses><t:Entry Key="EmailAddress1">SMTP:%s</t:Entry></t:EmailAddresses>'
                    '<t:PhoneNumbers><t:Entry Key="BusinessPhone">%s</t:Entry></t:PhoneNumbers>'
                    '<t:Department>%s</t:Department><t:JobTitle>%s</t:JobTitle><t:OfficeLocation>%s</t:OfficeLocation><t:Surname>%s</t:Surname></t:Contact>'
                    % (esc(n), esc(given), esc(company), esc(e), esc(phone), esc(dept), esc(job), esc(office), esc(surname)))
        res = ''.join('<t:Resolution><t:Mailbox><t:Name>%s</t:Name><t:EmailAddress>%s</t:EmailAddress><t:RoutingType>SMTP</t:RoutingType>'
                      '<t:MailboxType>%s</t:MailboxType></t:Mailbox>%s</t:Resolution>' % (esc(n), esc(e), k, details(n, e, k)) for n, e, k in found)
        cls = 'Warning' if len(found) > 1 else 'Success'
        code = 'ErrorNameResolutionMultipleResults' if len(found) > 1 else 'NoError'
        return wrap(op, ['<m:ResolveNamesResponseMessage ResponseClass="%s"><m:ResponseCode>%s</m:ResponseCode>'
                         '<m:ResolutionSet TotalItemsInView="%d" IncludesLastItemInRange="true">%s</m:ResolutionSet></m:ResolveNamesResponseMessage>'
                         % (cls, code, len(found), res)])
    return None


# ----------------------------------------------------------------------------
# delegates of the user's mailbox
# ----------------------------------------------------------------------------
DELEGATES = {'configured': False, 'deliver': 'DelegatesAndMe', 'users': {}}
PERM_FIELDS = ['CalendarFolderPermissionLevel', 'TasksFolderPermissionLevel', 'InboxFolderPermissionLevel', 'ContactsFolderPermissionLevel']


def delegate_response(op, users_xml='', root_error=None):
    head = ('<m:%s ResponseClass="%s" xmlns:m="%s" xmlns:t="%s"><m:ResponseCode>%s</m:ResponseCode>'
            % (op, 'Error' if root_error else 'Success', NS['m'], NS['t'], root_error or 'NoError'))
    body = '<m:ResponseMessages>%s</m:ResponseMessages>' % users_xml if users_xml else ''
    tail = '<m:DeliverMeetingRequests>%s</m:DeliverMeetingRequests>' % DELEGATES['deliver'] if op == 'GetDelegateResponse' and not root_error else ''
    return head + body + tail + '</m:%s>' % op


def delegate_user_xml(email):
    u = DELEGATES['users'][email]
    name = next((n for n, e in DIRECTORY if e == email), email)
    perms = ''.join('<t:%s>%s</t:%s>' % (f, u['perms'].get(f, 'None'), f) for f in PERM_FIELDS)
    return ('<m:DelegateUserResponseMessageType ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode><m:DelegateUser>'
            '<t:UserId><t:SID>S-1-5-%d</t:SID><t:PrimarySmtpAddress>%s</t:PrimarySmtpAddress><t:DisplayName>%s</t:DisplayName></t:UserId>'
            '<t:DelegatePermissions>%s</t:DelegatePermissions><t:ReceiveCopiesOfMeetingMessages>%s</t:ReceiveCopiesOfMeetingMessages>'
            '<t:ViewPrivateItems>%s</t:ViewPrivateItems></m:DelegateUser></m:DelegateUserResponseMessageType>'
            % (abs(hash(email)) % 100000, esc(email), esc(name), perms, 'true' if u['copies'] else 'false', 'true' if u['private'] else 'false'))


def delegate_error(code, text):
    return ('<m:DelegateUserResponseMessageType ResponseClass="Error"><m:MessageText>%s</m:MessageText><m:ResponseCode>%s</m:ResponseCode>'
            '</m:DelegateUserResponseMessageType>' % (esc(text), code))


def handle_delegates(op, el, user):
    resp = op + 'Response'
    mailbox = text(el, 'm:Mailbox/t:EmailAddress').lower()
    if mailbox != user.lower():
        return delegate_response(resp, root_error='ErrorAccessDenied')
    if op == 'GetDelegate':
        if not DELEGATES['configured']:
            return delegate_response(resp, root_error='ErrorDelegateMissingConfiguration')
        return delegate_response(resp, ''.join(delegate_user_xml(e) for e in DELEGATES['users']))
    if op == 'RemoveDelegate':
        out = ''
        for uid in el.findall('m:UserIds/t:UserId', NS):
            email = text(uid, 't:PrimarySmtpAddress').lower()
            if email not in DELEGATES['users']:
                out += delegate_error('ErrorNotDelegate', 'not a delegate')
            else:
                del DELEGATES['users'][email]
                out += '<m:DelegateUserResponseMessageType ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode></m:DelegateUserResponseMessageType>'
        return delegate_response(resp, out)
    deliver = text(el, 'm:DeliverMeetingRequests')
    if deliver:
        DELEGATES['deliver'] = deliver
    out = ''
    for du in el.findall('m:DelegateUsers/t:DelegateUser', NS):
        email = text(du, 't:UserId/t:PrimarySmtpAddress').lower()
        perms = {f: text(du, 't:DelegatePermissions/t:' + f, 'None') for f in PERM_FIELDS}
        copies = text(du, 't:ReceiveCopiesOfMeetingMessages') == 'true'
        if email not in [e for _, e in DIRECTORY]:
            out += delegate_error('ErrorDelegateNoUser', 'The user could not be found.')
            continue
        if op == 'AddDelegate' and email in DELEGATES['users']:
            out += delegate_error('ErrorDelegateAlreadyExists', 'already a delegate')
            continue
        if op == 'UpdateDelegate' and email not in DELEGATES['users']:
            out += delegate_error('ErrorNotDelegate', 'not a delegate')
            continue
        if copies and perms['CalendarFolderPermissionLevel'] != 'Editor':
            out += delegate_error('ErrorDelegateValidationFailed', 'meeting copies need calendar editor')
            continue
        DELEGATES['users'][email] = dict(perms=perms, copies=copies, private=text(du, 't:ViewPrivateItems') == 'true')
        DELEGATES['configured'] = True
        out += delegate_user_xml(email)
    return delegate_response(resp, out)


def seed_delegate_request(add_message):
    """A copy of an invitation sent to Marie, received by the user as her delegate."""
    start, end = at(8, 9), at(8, 10)
    eid = add_event('Revue trimestrielle', start, end, location='Salle du conseil', folder='shared:marie.martin@societe-a.fr',
                    organizer=('Paul Durand', 'paul.durand@societe-a.fr'), response='NoResponseReceived',
                    attendees=[('Marie Martin', 'marie.martin@societe-a.fr', 'NoResponseReceived')], free_busy='Tentative')
    mid = add_message('F-INBOX', 'Invitation : Revue trimestrielle', ('Paul Durand', 'paul.durand@societe-a.fr'),
                      '<p>Marie, je t\'invite à la revue trimestrielle.</p>', 60 * 30, is_read=True,
                      to=(('Marie Martin', 'marie.martin@societe-a.fr'),))
    return mid, dict(start=start, end=end, location='Salle du conseil', response='NoResponseReceived', event=eid,
                     representing=('Marie Martin', 'marie.martin@societe-a.fr'))


def seed_meeting_request(add_message):
    """An invitation e-mail in the inbox, linked to a tentative event."""
    start, end = at(6, 10), at(6, 11, 30)
    eid = add_event('Comité de pilotage', start, end, location='Visio Teams', organizer=('Paul Durand', 'paul.durand@societe-a.fr'),
                    response='NoResponseReceived', attendees=[(ME[0], ME[1], 'NoResponseReceived')], free_busy='Tentative')
    mid = add_message('F-INBOX', 'Invitation : Comité de pilotage', ('Paul Durand', 'paul.durand@societe-a.fr'),
                      '<p>Bonjour, je vous invite au comité de pilotage.</p>', 60 * 20)
    return mid, dict(start=start, end=end, location='Visio Teams', response='NoResponseReceived', event=eid)
