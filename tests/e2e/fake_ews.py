#!/usr/bin/env python3
"""
Minimal fake Exchange Web Services endpoint for end-to-end tests of the
Nextcloud webmail app (no real Exchange needed).

  python3 fake_ews.py --port 8443 --cert cert.pem --key key.pem --log /tmp/ews-requests

Accepts Basic auth for any "*@<domain>" user with password "secret".
Every request body is written to --log so it can be validated against the
official EWS XSD afterwards (tests/e2e/validate_logged_requests.php).
"""
import argparse
import base64
import datetime as dt
import html
import itertools
import os
import ssl
import threading
import xml.etree.ElementTree as ET
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import fake_ews_pim as pim

NS = {
    's': 'http://schemas.xmlsoap.org/soap/envelope/',
    'm': 'http://schemas.microsoft.com/exchange/services/2006/messages',
    't': 'http://schemas.microsoft.com/exchange/services/2006/types',
}
M = '{%s}' % NS['m']
T = '{%s}' % NS['t']

LOCK = threading.Lock()
COUNTER = itertools.count(1000)
LOG_DIR = None
REQ_COUNTER = itertools.count(1)


def now(minutes_ago=0):
    return (dt.datetime.now(dt.timezone.utc) - dt.timedelta(minutes=minutes_ago)).strftime('%Y-%m-%dT%H:%M:%SZ')


FOLDERS = [
    # id, name, parent, role
    ('F-ROOT', 'Haut de la banque d\'informations', None, 'msgfolderroot'),
    ('F-INBOX', 'Boîte de réception', 'F-ROOT', 'inbox'),
    ('F-DRAFTS', 'Brouillons', 'F-ROOT', 'drafts'),
    ('F-SENT', 'Éléments envoyés', 'F-ROOT', 'sentitems'),
    ('F-DELETED', 'Éléments supprimés', 'F-ROOT', 'deleteditems'),
    ('F-JUNK', 'Courrier indésirable', 'F-ROOT', 'junkemail'),
    ('F-CLIENTS', 'Clients', 'F-INBOX', None),
    ('F-FACTURES', 'Factures', 'F-ROOT', None),
]
ROLE_TO_ID = {f[3]: f[0] for f in FOLDERS if f[3]}

PNG_1PX = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')

MESSAGES = {}
ATTACHMENTS = {}


def add_message(folder, subject, sender, body, minutes_ago, is_read=False, attachments=(), importance='Normal',
                flagged=False, to=(('Utilisateur', 'user@societe-a.fr'),), cc=()):
    mid = 'MSG-%d' % next(COUNTER)
    atts = []
    for a in attachments:
        aid = 'ATT-%d' % next(COUNTER)
        ATTACHMENTS[aid] = dict(a, id=aid)
        atts.append(aid)
    MESSAGES[mid] = dict(id=mid, ck='CK-%d' % next(COUNTER), folder=folder, subject=subject, sender=sender, body=body,
                         date=now(minutes_ago), is_read=is_read, attachments=atts, importance=importance,
                         flagged=flagged, to=list(to), cc=list(cc))
    return mid


def seed():
    add_message('F-INBOX', 'Devis pour la maintenance annuelle', ('Jean Dupont', 'jean.dupont@client-exemple.fr'),
                '<html><head><style>p{margin:0 0 8px}</style></head><body><p>Bonjour,</p><p>Veuillez trouver ci-joint notre <b>devis</b> pour la maintenance 2027.</p>'
                '<p><img src="cid:logo@client" alt="logo"> <img src="https://tracker.example/pixel.gif" width="1" height="1"></p>'
                '<script>alert("xss")</script><p onclick="alert(1)">Cordialement,<br>Jean Dupont</p></body></html>',
                12, attachments=[
                    dict(name='devis-2027.pdf', content_type='application/pdf', content=b'%PDF-1.4\n% fake pdf\n', inline=False),
                    dict(name='logo.png', content_type='image/png', content=PNG_1PX, inline=True, content_id='logo@client'),
                ], importance='High')
    add_message('F-INBOX', 'Réunion de lundi', ('Marie Martin', 'marie.martin@societe-a.fr'),
                '<html><body><div>Salut,<br><br>On décale la réunion à 14h, ça te va ?<br><br>Marie</div></body></html>', 95, cc=(('Paul', 'paul@societe-a.fr'),))
    add_message('F-INBOX', 'Votre facture OVH', ('OVHcloud', 'noreply@ovh.example'),
                '<html><body><p>Votre facture est disponible dans votre espace client.</p></body></html>', 60 * 26, is_read=True, flagged=True)
    for i in range(1, 58):
        add_message('F-INBOX', 'Newsletter n°%d' % i, ('Lettre d\'info', 'news@exemple.org'),
                    '<p>Contenu %d</p>' % i, 60 * 24 * (2 + i), is_read=True)
    add_message('F-CLIENTS', 'Commande 4521', ('Client B', 'contact@client-b.fr'), '<p>Merci pour la livraison.</p>', 60 * 50, is_read=True)
    add_message('F-SENT', 'Re: Planning', ('Utilisateur', 'user@societe-a.fr'), '<p>OK pour moi.</p>', 300, is_read=True,
                to=(('Marie Martin', 'marie.martin@societe-a.fr'),))


seed()
_mr, _meeting = pim.seed_meeting_request(add_message)
MESSAGES[_mr]['kind'] = 'MeetingRequest'
MESSAGES[_mr]['meeting'] = _meeting
_dr, _delegate = pim.seed_delegate_request(add_message)
MESSAGES[_dr]['kind'] = 'MeetingRequest'
MESSAGES[_dr]['meeting'] = _delegate


# ----------------------------------------------------------------------------
def esc(s):
    return html.escape(str(s), quote=True)


def mailbox(name, email):
    return '<t:Mailbox><t:Name>%s</t:Name><t:EmailAddress>%s</t:EmailAddress><t:RoutingType>SMTP</t:RoutingType></t:Mailbox>' % (esc(name), esc(email))


def envelope(body):
    return ('<?xml version="1.0" encoding="utf-8"?><s:Envelope xmlns:s="%s"><s:Header>'
            '<h:ServerVersionInfo MajorVersion="15" MinorVersion="1" MajorBuildNumber="2507" MinorBuildNumber="6" '
            'xmlns:h="%s"/></s:Header><s:Body>%s</s:Body></s:Envelope>' % (NS['s'], NS['t'], body))


def wrap(op, messages):
    return ('<m:%sResponse xmlns:m="%s" xmlns:t="%s"><m:ResponseMessages>%s</m:ResponseMessages></m:%sResponse>'
            % (op, NS['m'], NS['t'], ''.join(messages), op))


def ok(op, inner=''):
    return '<m:%sResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode>%s</m:%sResponseMessage>' % (op, inner, op)


def err(op, code, text):
    return ('<m:%sResponseMessage ResponseClass="Error"><m:MessageText>%s</m:MessageText><m:ResponseCode>%s</m:ResponseCode>'
            '<m:DescriptiveLinkKey>0</m:DescriptiveLinkKey></m:%sResponseMessage>' % (op, esc(text), code, op))


def folder_id(el):
    """Resolve a t:FolderId / t:DistinguishedFolderId element to our folder id."""
    if el.tag == T + 'DistinguishedFolderId':
        return ROLE_TO_ID.get(el.get('Id'))
    return el.get('Id')


def counts(fid):
    msgs = [m for m in MESSAGES.values() if m['folder'] == fid]
    return len(msgs), sum(1 for m in msgs if not m['is_read'])


# mailbox size: the inbox holds 1.2 GB of old mail, each message adds 60 KB; limit 2 GB (KB)
OLD_MAIL_BYTES = 1288490188
QUOTA_KB = 2097152


def size_xml(nbytes):
    return '<t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0xe08" PropertyType="Long"/><t:Value>%d</t:Value></t:ExtendedProperty>' % nbytes


def folder_xml(f, with_size=False):
    fid, name, parent, _ = f
    total, unread = counts(fid)
    children = sum(1 for x in FOLDERS if x[2] == fid)
    size = size_xml(total * 61440 + (OLD_MAIL_BYTES if fid == 'F-INBOX' else 0)) if with_size else ''
    return ('<t:Folder><t:FolderId Id="%s" ChangeKey="AQ"/>%s<t:FolderClass>IPF.Note</t:FolderClass><t:DisplayName>%s</t:DisplayName>'
            '<t:TotalCount>%d</t:TotalCount><t:ChildFolderCount>%d</t:ChildFolderCount><t:UnreadCount>%d</t:UnreadCount>%s</t:Folder>'
            % (fid, '<t:ParentFolderId Id="%s" ChangeKey="AQ"/>' % parent if parent else '', esc(name), total, children, unread, size))


def item_summary_xml(m):
    import re
    text = re.sub(r'<(script|style)[^>]*>.*?</\1>', '', m['body'], flags=re.S)
    text = html.unescape(re.sub(r'<[^>]+>', ' ', text))
    text = ' '.join(text.split())[:255]
    display_to = '; '.join(n for n, _ in m['to'])
    return (('<t:Message><t:ItemId Id="%s" ChangeKey="%s"/><t:Subject>%s</t:Subject><t:DateTimeReceived>%s</t:DateTimeReceived>'
            '<t:HasAttachments>%s</t:HasAttachments><t:Importance>%s</t:Importance><t:DisplayTo>%s</t:DisplayTo><t:Preview>%s</t:Preview>'
            '<t:Flag><t:FlagStatus>%s</t:FlagStatus></t:Flag><t:IsRead>%s</t:IsRead><t:From>%s</t:From></t:Message>').replace('Message>', m.get('kind', 'Message') + '>')
            % (m['id'], m['ck'], esc(m['subject']), m['date'],
               'true' if any(not ATTACHMENTS[a].get('inline') for a in m['attachments']) else 'false',
               m['importance'], esc(display_to), esc(text),
               'Flagged' if m['flagged'] else 'NotFlagged', 'true' if m['is_read'] else 'false', mailbox(*m['sender'])))


def item_full_xml(m):
    xml = _item_full_xml(m)
    kind = m.get('kind', 'Message')
    head, _, tail = xml.replace('<t:Message>', '<t:%s>' % kind, 1).rpartition('</t:Message>')
    return head + '</t:%s>' % kind + tail


def _item_full_xml(m):
    atts = ''
    for aid in m['attachments']:
        a = ATTACHMENTS[aid]
        atts += ('<t:FileAttachment><t:AttachmentId Id="%s"/><t:Name>%s</t:Name><t:ContentType>%s</t:ContentType>%s'
                 '<t:Size>%d</t:Size><t:IsInline>%s</t:IsInline></t:FileAttachment>'
                 % (aid, esc(a['name']), a['content_type'],
                    '<t:ContentId>%s</t:ContentId>' % esc(a['content_id']) if a.get('content_id') else '',
                    len(a['content']), 'true' if a.get('inline') else 'false'))
    to = ''.join(mailbox(n, e) for n, e in m['to'])
    cc = ''.join(mailbox(n, e) for n, e in m['cc'])
    return ('<t:Message><t:ItemId Id="%s" ChangeKey="%s"/><t:ParentFolderId Id="%s" ChangeKey="AQ"/><t:Subject>%s</t:Subject>'
            '<t:Body BodyType="HTML" IsTruncated="false">%s</t:Body>%s<t:DateTimeReceived>%s</t:DateTimeReceived>'
            '<t:HasAttachments>%s</t:HasAttachments><t:Importance>%s</t:Importance><t:DateTimeSent>%s</t:DateTimeSent>'
            '<t:Flag><t:FlagStatus>%s</t:FlagStatus></t:Flag><t:Sender>%s</t:Sender>%s%s<t:IsRead>%s</t:IsRead><t:From>%s</t:From>'
            '<t:InternetMessageId>&lt;%s@fake&gt;</t:InternetMessageId></t:Message>'
            % (m['id'], m['ck'], m['folder'], esc(m['subject']), esc(m['body']),
               '<t:Attachments>%s</t:Attachments>' % atts if atts else '', m['date'],
               'true' if m['attachments'] else 'false', m['importance'], m['date'],
               'Flagged' if m['flagged'] else 'NotFlagged', mailbox(*m['sender']),
               '<t:ToRecipients>%s</t:ToRecipients>' % to if to else '', '<t:CcRecipients>%s</t:CcRecipients>' % cc if cc else '',
               'true' if m['is_read'] else 'false', mailbox(*m['sender']), m['id']))


def recipients(el, name):
    out = []
    r = el.find('t:' + name, NS)
    if r is not None:
        for mb in r.findall('t:Mailbox', NS):
            e = mb.findtext('t:EmailAddress', default='', namespaces=NS)
            out.append((e.split('@')[0], e))
    return out


# ----------------------------------------------------------------------------
CURRENT_TZ = 'UTC'


def move_message(mid, folder):
    MESSAGES[mid]['folder'] = folder


def handle(op, el, user):
    r = pim.handle(op, el, user, CURRENT_TZ, MESSAGES, move_message)
    if r is not None:
        return r
    if op == 'GetFolder':
        msgs = []
        for f in el.find('m:FolderIds', NS):
            fid = folder_id(f)
            if fid is None:
                msgs.append(err(op, 'ErrorFolderNotFound', 'The specified folder could not be found in the store.'))
            else:
                quota = ''
                if fid == 'F-ROOT' and any(u.get('PropertyTag', '').lower() == '0x666e' for u in el.iter(T + 'ExtendedFieldURI')):
                    quota = ('<t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0x666e" PropertyType="Integer"/><t:Value>%d</t:Value></t:ExtendedProperty>'
                             % QUOTA_KB)
                msgs.append(ok(op, '<m:Folders><t:Folder><t:FolderId Id="%s" ChangeKey="AQ"/>%s</t:Folder></m:Folders>' % (fid, quota)))
        return wrap(op, msgs)

    if op == 'FindFolder':
        cal = ('<t:CalendarFolder><t:FolderId Id="F-CAL" ChangeKey="AQ"/><t:ParentFolderId Id="F-ROOT" ChangeKey="AQ"/>'
               '<t:FolderClass>IPF.Appointment</t:FolderClass><t:DisplayName>Calendrier</t:DisplayName><t:TotalCount>0</t:TotalCount>'
               '<t:ChildFolderCount>1</t:ChildFolderCount></t:CalendarFolder>'
               '<t:CalendarFolder><t:FolderId Id="F-CAL2" ChangeKey="AQ"/><t:ParentFolderId Id="F-CAL" ChangeKey="AQ"/>'
               '<t:FolderClass>IPF.Appointment</t:FolderClass><t:DisplayName>Congés équipe</t:DisplayName><t:TotalCount>0</t:TotalCount>'
               '<t:ChildFolderCount>0</t:ChildFolderCount></t:CalendarFolder>'
               '<t:ContactsFolder><t:FolderId Id="F-CONTACTS" ChangeKey="AQ"/><t:ParentFolderId Id="F-ROOT" ChangeKey="AQ"/>'
               '<t:FolderClass>IPF.Contact</t:FolderClass><t:DisplayName>Contacts</t:DisplayName><t:TotalCount>0</t:TotalCount>'
               '<t:ChildFolderCount>1</t:ChildFolderCount></t:ContactsFolder>'
               '<t:ContactsFolder><t:FolderId Id="F-CONTACTS2" ChangeKey="AQ"/><t:ParentFolderId Id="F-CONTACTS" ChangeKey="AQ"/>'
               '<t:FolderClass>IPF.Contact</t:FolderClass><t:DisplayName>Fournisseurs</t:DisplayName><t:TotalCount>0</t:TotalCount>'
               '<t:ChildFolderCount>0</t:ChildFolderCount></t:ContactsFolder>'
               '<t:ContactsFolder><t:FolderId Id="F-RCACHE" ChangeKey="AQ"/><t:ParentFolderId Id="F-CONTACTS" ChangeKey="AQ"/>'
               '<t:FolderClass>IPF.Contact.RecipientCache</t:FolderClass><t:DisplayName>Recipient Cache</t:DisplayName><t:TotalCount>0</t:TotalCount>'
               '<t:ChildFolderCount>0</t:ChildFolderCount></t:ContactsFolder>')
        with_size = any(u.get('PropertyTag') == '0x0E08' for u in el.iter(T + 'ExtendedFieldURI'))
        folders = ''.join(folder_xml(f, with_size) for f in FOLDERS if f[3] != 'msgfolderroot') + cal
        return wrap(op, [ok(op, '<m:RootFolder TotalItemsInView="%d" IncludesLastItemInRange="true"><t:Folders>%s</t:Folders></m:RootFolder>'
                            % (len(FOLDERS), folders))])

    if op == 'FindItem':
        fid = folder_id(el.find('m:ParentFolderIds', NS)[0])
        if fid is None or fid not in {f[0] for f in FOLDERS}:
            return wrap(op, [err(op, 'ErrorFolderNotFound', 'Folder not found')])
        view = el.find('m:IndexedPageItemView', NS)
        offset, limit = int(view.get('Offset', 0)), int(view.get('MaxEntriesReturned', 50))
        q = (el.findtext('m:QueryString', default='', namespaces=NS) or '').lower()
        msgs = [m for m in MESSAGES.values() if m['folder'] == fid]
        if q:
            msgs = [m for m in msgs if q in m['subject'].lower() or q in m['body'].lower() or q in m['sender'][0].lower()]
        msgs.sort(key=lambda m: m['date'], reverse=True)
        page = msgs[offset:offset + limit]
        last = offset + limit >= len(msgs)
        return wrap(op, [ok(op, '<m:RootFolder IndexedPagingOffset="%d" TotalItemsInView="%d" IncludesLastItemInRange="%s"><t:Items>%s</t:Items></m:RootFolder>'
                            % (offset + len(page), len(msgs), 'true' if last else 'false', ''.join(item_summary_xml(m) for m in page)))])

    if op == 'GetItem':
        shape = el.findtext('m:ItemShape/t:BaseShape', default='', namespaces=NS)
        msgs = []
        for i in el.find('m:ItemIds', NS):
            m = MESSAGES.get(i.get('Id'))
            if not m:
                msgs.append(err(op, 'ErrorItemNotFound', 'The specified object was not found in the store.'))
            elif shape == 'IdOnly' and el.find('m:ItemShape/t:AdditionalProperties', NS) is None:
                msgs.append(ok(op, '<m:Items><t:Message><t:ItemId Id="%s" ChangeKey="%s"/></t:Message></m:Items>' % (m['id'], m['ck'])))
            else:
                msgs.append(ok(op, '<m:Items>%s</m:Items>' % item_full_xml(m)))
        return wrap(op, msgs)

    if op == 'GetAttachment':
        msgs = []
        for a in el.find('m:AttachmentIds', NS):
            att = ATTACHMENTS.get(a.get('Id'))
            if not att:
                msgs.append(err(op, 'ErrorInvalidIdMalformed', 'Id is malformed.'))
                continue
            msgs.append(ok(op, '<m:Attachments><t:FileAttachment><t:AttachmentId Id="%s"/><t:Name>%s</t:Name><t:ContentType>%s</t:ContentType>'
                               '<t:Content>%s</t:Content></t:FileAttachment></m:Attachments>'
                           % (att['id'], esc(att['name']), att['content_type'], base64.b64encode(att['content']).decode())))
        return wrap(op, msgs)

    if op == 'UpdateItem':
        msgs = []
        for ch in el.find('m:ItemChanges', NS):
            m = MESSAGES.get(ch.find('t:ItemId', NS).get('Id'))
            if not m:
                msgs.append(err(op, 'ErrorItemNotFound', 'not found'))
                continue
            for upd in ch.find('t:Updates', NS):
                r = upd.findtext('.//t:IsRead', default=None, namespaces=NS)
                if r is not None:
                    m['is_read'] = r == 'true'
                fs = upd.findtext('.//t:FlagStatus', default=None, namespaces=NS)
                if fs is not None:
                    m['flagged'] = fs == 'Flagged'
            m['ck'] = 'CK-%d' % next(COUNTER)
            msgs.append(ok(op, '<m:Items><t:Message><t:ItemId Id="%s" ChangeKey="%s"/></t:Message></m:Items><m:ConflictResults><t:Count>0</t:Count></m:ConflictResults>' % (m['id'], m['ck'])))
        return wrap(op, msgs)

    if op in ('MoveItem', 'DeleteItem'):
        target = folder_id(el.find('m:ToFolderId', NS)[0]) if op == 'MoveItem' else 'F-DELETED'
        hard = el.get('DeleteType') == 'HardDelete'
        msgs = []
        for i in el.find('m:ItemIds', NS):
            m = MESSAGES.get(i.get('Id'))
            if not m:
                msgs.append(err(op, 'ErrorItemNotFound', 'not found'))
                continue
            if hard:
                del MESSAGES[m['id']]
            else:
                m['folder'] = target
            msgs.append(ok(op, '<m:Items/>' if op == 'MoveItem' else ''))
        return wrap(op, msgs)

    if op == 'CreateItem':
        disposition = el.get('MessageDisposition')
        msgs = []
        for it in el.find('m:Items', NS):
            tag = it.tag.replace(T, '')
            if tag == 'Message':
                subject = it.findtext('t:Subject', default='', namespaces=NS)
                body = it.findtext('t:Body', default='', namespaces=NS)
            else:
                ref = MESSAGES.get(it.find('t:ReferenceItemId', NS).get('Id'))
                if ref is None:
                    msgs.append(err(op, 'ErrorItemNotFound', 'not found'))
                    continue
                prefix = 'TR: ' if tag == 'ForwardItem' else 'RE: '
                subject = prefix + ref['subject']
                body = it.findtext('t:NewBodyContent', default='', namespaces=NS) + '<hr>' + ref['body']
            to = recipients(it, 'ToRecipients')
            if tag in ('ReplyToItem', 'ReplyAllToItem'):
                to = [ref['sender']] + to
            if not to and disposition != 'SaveOnly':
                msgs.append(err(op, 'ErrorInvalidRecipients', 'At least one recipient is required.'))
                continue
            atts = []
            for a in it.findall('t:Attachments/t:FileAttachment', NS):
                aid = 'ATT-%d' % next(COUNTER)
                ATTACHMENTS[aid] = dict(id=aid, name=a.findtext('t:Name', namespaces=NS), content_type=a.findtext('t:ContentType', default='application/octet-stream', namespaces=NS),
                                        content=base64.b64decode(a.findtext('t:Content', default='', namespaces=NS)), inline=False)
                atts.append(aid)
            if tag == 'ForwardItem':
                atts += list(ref['attachments'])
            folder = 'F-SENT' if disposition == 'SendAndSaveCopy' else 'F-DRAFTS'
            mid = add_message(folder, subject, ('Utilisateur', user), body, 0, is_read=True, to=to, cc=recipients(it, 'CcRecipients'))
            MESSAGES[mid]['attachments'] = atts
            items = '<m:Items/>' if disposition == 'SendAndSaveCopy' else '<m:Items><t:Message><t:ItemId Id="%s" ChangeKey="%s"/></t:Message></m:Items>' % (mid, MESSAGES[mid]['ck'])
            msgs.append(ok(op, items))
        return wrap(op, msgs)

    if op == 'CreateAttachment':
        parent = MESSAGES.get(el.find('m:ParentItemId', NS).get('Id'))
        if parent is None:
            return wrap(op, [err(op, 'ErrorItemNotFound', 'not found')])
        out = []
        for a in el.find('m:Attachments', NS):
            aid = 'ATT-%d' % next(COUNTER)
            ATTACHMENTS[aid] = dict(id=aid, name=a.findtext('t:Name', namespaces=NS), content_type=a.findtext('t:ContentType', default='application/octet-stream', namespaces=NS),
                                    content=base64.b64decode(a.findtext('t:Content', default='', namespaces=NS)), inline=False)
            parent['attachments'].append(aid)
            parent['ck'] = 'CK-%d' % next(COUNTER)
            out.append(ok(op, '<m:Attachments><t:FileAttachment><t:AttachmentId Id="%s" RootItemId="%s" RootItemChangeKey="%s"/></t:FileAttachment></m:Attachments>'
                          % (aid, parent['id'], parent['ck'])))
        return wrap(op, out)

    if op == 'SendItem':
        msgs = []
        for i in el.find('m:ItemIds', NS):
            m = MESSAGES.get(i.get('Id'))
            if not m:
                msgs.append(err(op, 'ErrorItemNotFound', 'not found'))
            elif i.get('ChangeKey') != m['ck']:
                msgs.append(err(op, 'ErrorIrresolvableConflict', 'The send or update operation could not be performed because the change key passed in the request does not match the current change key for the item.'))
            else:
                m['folder'] = 'F-SENT'
                msgs.append(ok(op))
        return wrap(op, msgs)

    return None


class Handler(BaseHTTPRequestHandler):
    protocol_version = 'HTTP/1.1'

    def log_message(self, fmt, *args):  # quieter
        pass

    def send(self, status, body, extra=None):
        data = body.encode('utf-8')
        self.send_response(status)
        self.send_header('Content-Type', 'text/xml; charset=utf-8')
        self.send_header('Content-Length', str(len(data)))
        for k, v in (extra or {}).items():
            self.send_header(k, v)
        self.end_headers()
        self.wfile.write(data)

    def autodiscover(self, raw):
        """SOAP Autodiscover, GetUserSettings: only PublicFolderInformation."""
        ad = 'http://schemas.microsoft.com/exchange/2010/Autodiscover'
        root = ET.fromstring(raw)
        mailbox = root.findtext('.//{%s}Mailbox' % ad, default='')
        setting = root.findtext('.//{%s}Setting' % ad, default='')
        if setting != 'PublicFolderInformation' or self.headers.get('SOAPAction', '').strip('"') != ad + '/Autodiscover/GetUserSettings':
            self.send(400, '')
            return
        body = ('<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
                '<GetUserSettingsResponseMessage xmlns="%s"><Response><ErrorCode>NoError</ErrorCode><UserResponses><UserResponse>'
                '<ErrorCode>NoError</ErrorCode><UserSettings><UserSetting xmlns:i="http://www.w3.org/2001/XMLSchema-instance" i:type="StringSetting">'
                '<Name>PublicFolderInformation</Name><Value>%s</Value></UserSetting></UserSettings></UserResponse></UserResponses>'
                '</Response></GetUserSettingsResponseMessage></s:Body></s:Envelope>' % (ad, pim.PF_MAILBOX if mailbox else ''))
        self.send(200, body)

    def do_POST(self):
        length = int(self.headers.get('Content-Length', 0))
        raw = self.rfile.read(length)
        auth = self.headers.get('Authorization', '')
        user = None
        if auth.startswith('Basic '):
            u, _, p = base64.b64decode(auth[6:]).decode('utf-8', 'replace').partition(':')
            if p == 'secret' and '@' in u:
                user = u
        if user is None:
            self.send(401, '', {'WWW-Authenticate': 'Basic realm="ex.fake"'})
            return
        if self.path.lower().startswith('/autodiscover/autodiscover.svc'):
            self.autodiscover(raw)
            return
        if LOG_DIR:
            with open(os.path.join(LOG_DIR, '%04d.xml' % next(REQ_COUNTER)), 'wb') as f:
                f.write(raw)
        try:
            root = ET.fromstring(raw)
            body = root.find('s:Body', NS)[0]
            op = body.tag.replace(M, '')
            tzdef = root.find('s:Header/t:TimeZoneContext/t:TimeZoneDefinition', NS)
            with LOCK:
                global CURRENT_TZ
                CURRENT_TZ = tzdef.get('Id') if tzdef is not None else 'UTC'
                pim.REQUEST_HEADERS = dict(self.headers.items())
                resp = handle(op, body, user)
            if resp is None:
                self.send(500, envelope('<s:Fault><faultcode>s:Client</faultcode><faultstring>Unsupported operation %s</faultstring></s:Fault>' % esc(op)))
            else:
                self.send(200, envelope(resp))
        except Exception as e:  # pragma: no cover
            self.send(500, envelope('<s:Fault><faultcode>s:Server</faultcode><faultstring>%s</faultstring></s:Fault>' % esc(repr(e))))


def main():
    global LOG_DIR
    ap = argparse.ArgumentParser()
    ap.add_argument('--port', type=int, default=8443)
    ap.add_argument('--cert', required=True)
    ap.add_argument('--key', required=True)
    ap.add_argument('--log')
    args = ap.parse_args()
    LOG_DIR = args.log
    if LOG_DIR:
        os.makedirs(LOG_DIR, exist_ok=True)
    srv = ThreadingHTTPServer(('0.0.0.0', args.port), Handler)
    ctx = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    ctx.load_cert_chain(args.cert, args.key)
    srv.socket = ctx.wrap_socket(srv.socket, server_side=True)
    print('fake EWS listening on https://0.0.0.0:%d/EWS/Exchange.asmx' % args.port, flush=True)
    srv.serve_forever()


if __name__ == '__main__':
    main()
