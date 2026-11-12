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


def can_write(folder):
    return not folder.startswith('shared:') or SHARED_ACCESS.get(folder[7:]) == 'edit'


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
def handle(op, el, user, tz, messages, move_message):
    """messages / move_message give access to the mail store of fake_ews.py (meeting requests)."""

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
        if pid in ('contacts', 'F-CONTACTS', 'F-CONTACTS2'):
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
