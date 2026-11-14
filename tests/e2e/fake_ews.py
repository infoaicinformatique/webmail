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
import struct
import threading
import zlib
import xml.etree.ElementTree as ET
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

import fake_ews_pim as pim
import fake_ews_tasks as tasks
import fake_ews_rules as rules

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
    ('F-OUTBOX', 'Boîte d\'envoi', 'F-ROOT', 'outbox'),
    ('F-CLIENTS', 'Clients', 'F-INBOX', None),
    ('F-FACTURES', 'Factures', 'F-ROOT', None),
]
# junk e-mail options of the mailbox (MarkAsJunk)
BLOCKED_SENDERS, SAFE_SENDERS = set(), set()
ROLE_TO_ID = {f[3]: f[0] for f in FOLDERS if f[3]}
# a shared mailbox (contact@…): user@societe-a.fr has full access and may send as it
SHARED_MAILBOXES = {
    'contact@societe-a.fr': dict(name='Contact Société A', allowed={'user@societe-a.fr'}, folders=[
        ('C-ROOT', 'Haut de la banque d\'informations', None, 'msgfolderroot'),
        ('C-INBOX', 'Boîte de réception', 'C-ROOT', 'inbox'),
        ('C-DRAFTS', 'Brouillons', 'C-ROOT', 'drafts'),
        ('C-SENT', 'Éléments envoyés', 'C-ROOT', 'sentitems'),
        ('C-DELETED', 'Éléments supprimés', 'C-ROOT', 'deleteditems'),
        ('C-JUNK', 'Courrier indésirable', 'C-ROOT', 'junkemail'),
        ('C-DEVIS', 'Demandes de devis', 'C-INBOX', None),
    ]),
    # a colleague who made user@ their delegate in Outlook: the inbox (and its sub-folders) only
    'direction@societe-a.fr': dict(name='Sophie Direction', allowed={'user@societe-a.fr'}, delegated={'inbox'}, folders=[
        ('D-ROOT', 'Haut de la banque d\'informations', None, 'msgfolderroot'),
        ('D-INBOX', 'Boîte de réception', 'D-ROOT', 'inbox'),
        ('D-SENT', 'Éléments envoyés', 'D-ROOT', 'sentitems'),
        ('D-RAPPORTS', 'Rapports mensuels', 'D-INBOX', None),
    ]),
}
for _mb in SHARED_MAILBOXES.values():
    FOLDERS.extend(_mb['folders'])
CURRENT_USER = ''


def root_of(fid):
    """The msgfolderroot a folder belongs to (its mailbox)."""
    parents = {f[0]: f[2] for f in FOLDERS}
    while parents.get(fid):
        fid = parents[fid]
    return fid

def make_png(w, h, top, bottom):
    """A small vertical gradient, as a real PNG (for attachment previews)."""
    rows = b''
    for y in range(h):
        c = bytes(int(top[i] + (bottom[i] - top[i]) * y / max(1, h - 1)) for i in range(3))
        rows += b'\x00' + c * w
    chunk = lambda t, d: struct.pack('>I', len(d)) + t + d + struct.pack('>I', zlib.crc32(t + d) & 0xffffffff)
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', w, h, 8, 2, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(rows)) + chunk(b'IEND', b''))


PNG_1PX = base64.b64decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==')

MESSAGES = {}
ATTACHMENTS = {}
# conversations ignored (« Ignorer la conversation »)
IGNORED = set()


# pull subscriptions to the inbox: id -> True; the new messages of the inbox, in order (watermark = their count)
SUBSCRIPTIONS = {}
INBOX_EVENTS = []


def add_message(folder, subject, sender, body, minutes_ago, is_read=False, attachments=(), importance='Normal',
                flagged=False, to=(('Utilisateur', 'user@societe-a.fr'),), cc=()):
    mid = 'MSG-%d' % next(COUNTER)
    atts = []
    for a in attachments:
        aid = 'ATT-%d' % next(COUNTER)
        ATTACHMENTS[aid] = dict(a, id=aid)
        atts.append(aid)
    if folder == 'F-INBOX':
        # what pull subscriptions report (GetEvents)
        INBOX_EVENTS.append(mid)
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
                '<html><body><p>Votre facture est disponible dans votre espace client.</p>'
                '<p>Une question ? <a href="mailto:facturation@ovh.example?subject=Facture%20OVH&amp;body=Bonjour%2C">facturation@ovh.example</a></p></body></html>', 60 * 26, is_read=True, flagged=True)
    for i in range(1, 58):
        mid = add_message('F-INBOX', 'Newsletter n°%d' % i, ('Lettre d\'info', 'news@exemple.org'),
                          '<p>Contenu %d</p>' % i, 60 * 24 * (2 + i), is_read=True)
        # how to leave the list (List-Unsubscribe): a message to send; n°21 only a page to open
        MESSAGES[mid]['list_unsubscribe'] = ('<https://exemple.org/desinscription?n=21>' if i == 21 else
                                             '<mailto:desinscription@exemple.org?subject=Desinscription%20lettre%20d%27info>, <https://exemple.org/desinscription>')
    add_message('F-CLIENTS', 'Photos du chantier', ('Client B', 'contact@client-b.fr'),
                '<p>Bonjour, voici les photos et le relevé.</p>', 60 * 30, is_read=True, attachments=[
                    dict(name='facade.png', content_type='image/png', content=make_png(320, 200, (70, 130, 180), (230, 240, 250)), inline=False),
                    dict(name='toiture.png', content_type='image/png', content=make_png(320, 200, (160, 82, 45), (250, 235, 215)), inline=False),
                    dict(name='releve.txt', content_type='text/plain', content='Relevé du 12/11\nFaçade : 42 m²\nToiture : <b>58 m²</b>\n'.encode('utf-8'), inline=False),
                    dict(name='plan.pdf', content_type='application/pdf', content=b'%PDF-1.4\n% plan\n', inline=False),
                    dict(name='archive.zip', content_type='application/zip', content=b'PK\x05\x06' + b'\x00' * 18, inline=False),
                ])
    add_message('F-CLIENTS', 'Commande 4521', ('Client B', 'contact@client-b.fr'), '<p>Merci pour la livraison, le devis est signé.</p>', 60 * 50, is_read=True)
    # a conversation in this folder: the order, two answers (« grouped by conversation »)
    add_message('F-CLIENTS', 'RE: Commande 4521', ('Utilisateur', 'user@societe-a.fr'), '<p>Merci, la facture suit.</p>', 60 * 49, is_read=True,
                to=(('Client B', 'contact@client-b.fr'),))
    add_message('F-CLIENTS', 'RE: Commande 4521', ('Client B', 'contact@client-b.fr'), '<p>Facture bien reçue.</p>', 60 * 48)
    add_message('F-SENT', 'Re: Planning', ('Utilisateur', 'user@societe-a.fr'), '<p>OK pour moi.</p>', 300, is_read=True,
                to=(('Marie Martin', 'marie.martin@societe-a.fr'),))


seed()


def seed_shared():
    """Messages of the shared mailbox contact@societe-a.fr."""
    to = (('Contact Société A', 'contact@societe-a.fr'),)
    add_message('C-INBOX', 'Demande de devis cuisine', ('Claire Leroux', 'claire.leroux@particulier.example'),
                '<p>Bonjour,</p><p>Pourriez-vous me faire un devis pour la rénovation de ma cuisine ?</p><p>Claire Leroux</p>', 45, to=to)
    add_message('C-INBOX', 'Horaires d\'ouverture', ('Marc Petit', 'marc.petit@particulier.example'),
                '<p>Bonjour, êtes-vous ouverts le samedi ?</p>', 300, is_read=True, to=to)
    add_message('D-INBOX', 'Point budget du trimestre', ('Paul Durand', 'paul.durand@societe-a.fr'),
                '<p>Sophie, peux-tu valider le budget avant vendredi ?</p>', 30, to=(('Sophie Direction', 'direction@societe-a.fr'),))
    add_message('D-RAPPORTS', 'Rapport mensuel d\'octobre', ('Service Comptabilité', 'compta@societe-b.fr'),
                '<p>Le rapport d\'octobre est prêt.</p>', 3000, is_read=True, to=(('Sophie Direction', 'direction@societe-a.fr'),))
    add_message('C-DEVIS', 'Devis salle de bains', ('Nadia Benali', 'nadia.benali@particulier.example'),
                '<p>Bonjour, je souhaite un devis pour ma salle de bains.</p>', 60 * 30, is_read=True, to=to)


seed_shared()
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
        mailbox = (el.findtext('t:Mailbox/t:EmailAddress', default='', namespaces=NS) or '').lower()
        if mailbox and mailbox != CURRENT_USER.lower():
            shared = SHARED_MAILBOXES.get(mailbox)
            if not shared or CURRENT_USER.lower() not in shared['allowed']:
                return None
            if 'delegated' in shared and el.get('Id') not in shared['delegated']:
                return None  # a delegate only opens the folders shared with them
            return {f[3]: f[0] for f in shared['folders'] if f[3]}.get(el.get('Id'))
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


def deliver_to_self(m, user):
    """A message sent to oneself also arrives in the inbox (unread)."""
    if any(email.lower() == user.lower() for _, email in m['to'] + m['cc']):
        mid = add_message('F-INBOX', m['subject'], ('Utilisateur', user), m['body'], 0, to=m['to'], cc=m['cc'])
        # with its attachments, as Exchange delivers it
        MESSAGES[mid]['attachments'] = list(m.get('attachments') or [])
        if conversation_id(MESSAGES[mid]) in IGNORED:
            # an ignored conversation: straight to the deleted items
            MESSAGES[mid]['folder'] = 'F-DELETED'
            return
        rules.apply(user, MESSAGES[mid], lambda msg, folder: move_message(msg['id'], folder))


def item_summary_xml(m, with_folder=False):
    import re
    text = re.sub(r'<(script|style)[^>]*>.*?</\1>', '', m['body'], flags=re.S)
    text = html.unescape(re.sub(r'<[^>]+>', ' ', text))
    text = ' '.join(text.split())[:255]
    display_to = '; '.join(n for n, _ in m['to'])
    return (('<t:Message><t:ItemId Id="%s" ChangeKey="%s"/><t:Subject>%s</t:Subject><t:DateTimeReceived>%s</t:DateTimeReceived>'
            '<t:HasAttachments>%s</t:HasAttachments><t:Importance>%s</t:Importance><t:DisplayTo>%s</t:DisplayTo><t:Preview>%s</t:Preview>'
            '<t:Flag><t:FlagStatus>%s</t:FlagStatus>%s</t:Flag><t:IsRead>%s</t:IsRead><t:From>%s</t:From><t:Size>%d</t:Size></t:Message>').replace('Message>', m.get('kind', 'Message') + '>')
            .replace('<t:Importance>', categories_xml(m) + '<t:Importance>', 1)
            .replace('<t:Subject>', ('<t:ParentFolderId Id="%s" ChangeKey="AQ"/>' % m['folder'] if with_folder else '') + '<t:Subject>', 1)
            % (m['id'], m['ck'], esc(m['subject']), m['date'],
               'true' if any(not ATTACHMENTS[a].get('inline') for a in m['attachments']) else 'false',
               m['importance'], esc(display_to), esc(text),
               'Flagged' if m['flagged'] else 'NotFlagged', flag_dates(m), 'true' if m['is_read'] else 'false', mailbox(*m['sender']), size_of(m)))


def size_of(m):
    """The size of a message, as Exchange gives it (item:Size): the body and the attachments."""
    return 800 + len(m['body'].encode('utf-8')) + sum(len(ATTACHMENTS[a]['content']) for a in m['attachments'])


def categories_xml(m):
    cats = m.get('categories') or []
    return '<t:Categories>%s</t:Categories>' % ''.join('<t:String>%s</t:String>' % esc(c) for c in cats) if cats else ''


def conversation_id(m):
    """Exchange groups a message with its replies and forwards: here, the same subject without RE / TR."""
    import hashlib
    import re
    topic = re.sub(r'^\s*((re|tr|fw|fwd)\s*:\s*)+', '', m['subject'], flags=re.I).strip().lower()
    return 'CONV-' + hashlib.md5(topic.encode('utf-8')).hexdigest()[:12]


def preview_text(m):
    import re
    text = re.sub(r'<(script|style)[^>]*>.*?</\1>', '', m['body'], flags=re.S)
    text = html.unescape(re.sub(r'<[^>]+>', ' ', text))
    return ' '.join(text.split())[:255]


def mime_of(m):
    from email.message import EmailMessage
    from email.utils import formataddr
    msg = EmailMessage()
    msg['From'] = formataddr(m['sender'])
    msg['To'] = ', '.join(formataddr(t) for t in m.get('to', []))
    msg['Subject'] = m['subject']
    msg['Date'] = m['date']
    msg.set_content(m['body'], subtype='html')
    return bytes(msg)


def flag_dates(m):
    due = m.get('flag_due') if m['flagged'] else None
    return '<t:StartDate>%s</t:StartDate><t:DueDate>%s</t:DueDate>' % (due, due) if due else ''


def headers_of(m):
    """Internet headers of a received message: two servers on the way, and the checks of the receiving one
    (the newsletter's domain fails DKIM and DMARC)."""
    email = m['sender'][1]
    if email == 'user@societe-a.fr':
        return ''
    domain = email.split('@')[1]
    bad = domain == 'exemple.org'
    return ('Received: from EX3.mail.ovh.net (10.0.0.3) by EX3.mail.ovh.net (10.0.0.3) with Microsoft SMTP Server;\r\n'
            ' Fri, 13 Nov 2026 09:12:05 +0100\r\n'
            'Received: from mail.%s (mail.%s [203.0.113.7])\r\n\tby mx1.mail.ovh.net (Postfix) with ESMTPS id 4A1B2;\r\n'
            ' Fri, 13 Nov 2026 09:12:03 +0100\r\n'
            'Authentication-Results: mx1.mail.ovh.net; spf=pass smtp.mailfrom=%s;\r\n dkim=%s header.d=%s; dmarc=%s header.from=%s\r\n'
            'From: %s\r\nSubject: %s\r\nMessage-ID: <%s@%s>\r\n'
            % (domain, domain, domain, 'fail' if bad else 'pass', domain, 'fail' if bad else 'pass', domain,
               '%s <%s>' % m['sender'], m['subject'], m['id'], domain))


def item_full_xml(m):
    xml = _item_full_xml(m).replace('<t:Importance>', categories_xml(m) + '<t:Importance>', 1)
    # (Exchange gives item:Preview with the other properties)
    xml = xml.replace('<t:IsRead>', '<t:Preview>%s</t:Preview><t:IsRead>' % esc(preview_text(m)), 1)
    xml = xml.replace('<t:InternetMessageId>', '<t:ConversationId Id="%s"/><t:InternetMessageId>' % conversation_id(m), 1)
    if m.get('list_unsubscribe'):
        # each Internet header asked for (Exchange gives the name in lower case)
        xml = xml.replace('</t:Message>', '<t:ExtendedProperty><t:ExtendedFieldURI DistinguishedPropertySetId="InternetHeaders" PropertyName="list-unsubscribe" PropertyType="String"/>'
                                          '<t:Value>%s</t:Value></t:ExtendedProperty></t:Message>' % esc(m['list_unsubscribe']), 1)
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
    bcc = ''.join(mailbox(n, e) for n, e in m.get('bcc', []))
    return ('<t:Message><t:ItemId Id="%s" ChangeKey="%s"/><t:ParentFolderId Id="%s" ChangeKey="AQ"/><t:Subject>%s</t:Subject>'
            '<t:Body BodyType="HTML" IsTruncated="false">%s</t:Body>%s<t:DateTimeReceived>%s</t:DateTimeReceived>'
            '<t:HasAttachments>%s</t:HasAttachments><t:Importance>%s</t:Importance><t:DateTimeSent>%s</t:DateTimeSent>'
            '<t:Flag><t:FlagStatus>%s</t:FlagStatus>%s</t:Flag><t:Sender>%s</t:Sender>%s%s<t:IsRead>%s</t:IsRead><t:From>%s</t:From>'
            '<t:InternetMessageId>&lt;%s@fake&gt;</t:InternetMessageId></t:Message>'
            % (m['id'], m['ck'], m['folder'], esc(m['subject']), esc(m['body']),
               '<t:Attachments>%s</t:Attachments>' % atts if atts else '', m['date'],
               'true' if m['attachments'] else 'false', m['importance'], m['date'],
               'Flagged' if m['flagged'] else 'NotFlagged', flag_dates(m), mailbox(*m['sender']),
               '<t:ToRecipients>%s</t:ToRecipients>' % to if to else '',
               ('<t:CcRecipients>%s</t:CcRecipients>' % cc if cc else '') + ('<t:BccRecipients>%s</t:BccRecipients>' % bcc if bcc else ''),
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


def manage_folder(op, el):
    """Create, rename, move, delete mail folders (Exchange's own ones are refused)."""
    ids = {f[0]: i for i, f in enumerate(FOLDERS)}
    protected = {f[0] for f in FOLDERS if f[3]}
    sibling_names = lambda parent: {f[1].lower() for f in FOLDERS if f[2] == parent}
    if op == 'CreateFolder':
        parent = folder_id(el.find('m:ParentFolderId', NS)[0])
        name = el.findtext('m:Folders/t:Folder/t:DisplayName', default='', namespaces=NS)
        if parent not in ids:
            return wrap(op, [err(op, 'ErrorFolderNotFound', 'not found')])
        if name.lower() in sibling_names(parent):
            return wrap(op, [err(op, 'ErrorFolderExists', 'A folder with the specified name already exists.')])
        fid = 'F-NEW-%d' % next(COUNTER)
        FOLDERS.append((fid, name, parent, None))
        return wrap(op, [ok(op, '<m:Folders><t:Folder><t:FolderId Id="%s" ChangeKey="AQ"/></t:Folder></m:Folders>' % fid)])
    if op == 'UpdateFolder':
        out = []
        for ch in el.findall('m:FolderChanges/t:FolderChange', NS):
            fid = folder_id(ch[0])
            name = ch.findtext('.//t:Folder/t:DisplayName', default='', namespaces=NS)
            if fid not in ids:
                out.append(err(op, 'ErrorFolderNotFound', 'not found'))
            elif fid in protected:
                out.append(err(op, 'ErrorAccessDenied', 'Access is denied.'))
            elif name.lower() in sibling_names(FOLDERS[ids[fid]][2]) - {FOLDERS[ids[fid]][1].lower()}:
                out.append(err(op, 'ErrorFolderExists', 'exists'))
            else:
                f = FOLDERS[ids[fid]]
                FOLDERS[ids[fid]] = (fid, name, f[2], f[3])
                out.append(ok(op, '<m:Folders><t:Folder><t:FolderId Id="%s" ChangeKey="AQ"/></t:Folder></m:Folders>' % fid))
        return wrap(op, out)
    target = folder_id(el.find('m:ToFolderId', NS)[0]) if op == 'MoveFolder' else 'F-DELETED'
    out = []
    for ref in el.find('m:FolderIds', NS):
        fid = folder_id(ref)
        if fid not in ids:
            out.append(err(op, 'ErrorFolderNotFound', 'not found'))
            continue
        if fid in protected:
            out.append(err(op, 'ErrorDeleteDistinguishedFolder' if op == 'DeleteFolder' else 'ErrorMoveDistinguishedFolder', 'protected'))
            continue
        # not into itself or one of its sub-folders
        p = target
        while p is not None and p != fid:
            p = FOLDERS[ids[p]][2] if p in ids else None
        if p == fid:
            out.append(err(op, 'ErrorMoveCopyFailed', 'cannot move a folder into itself'))
            continue
        f = FOLDERS[ids[fid]]
        FOLDERS[ids[fid]] = (fid, f[1], target, f[3])
        out.append(ok(op, '<m:Folders><t:Folder><t:FolderId Id="%s" ChangeKey="AQ"/></t:Folder></m:Folders>' % fid if op == 'MoveFolder' else ''))
    return wrap(op, out)


# Outlook's master category list, kept per mailbox as Outlook does (XmlData of "CategoryList")
CATEGORY_LISTS = {}
DEFAULT_CATEGORIES = ('<?xml version="1.0"?><categories default="Catégorie rouge" lastSavedSession="1" xmlns="CategoryList.xsd">'
                      '<category name="Catégorie rouge" color="0" keyboardShortcut="0" guid="{00000000-0000-0000-0000-000000000001}" renameOnFirstUse="1"/>'
                      '<category name="Catégorie verte" color="4" keyboardShortcut="0" guid="{00000000-0000-0000-0000-000000000002}" renameOnFirstUse="1"/>'
                      '<category name="Clients" color="7" keyboardShortcut="0" guid="{00000000-0000-0000-0000-000000000003}"/>'
                      '</categories>')


def handle_user_configuration(op, el, user):
    if op not in ('GetUserConfiguration', 'UpdateUserConfiguration', 'CreateUserConfiguration'):
        return None
    name = el.find('.//t:UserConfigurationName', NS)
    if name is None:
        name = el.find('m:UserConfigurationName', NS)
    if name is None or name.get('Name') != 'CategoryList':
        return wrap(op, [err(op, 'ErrorItemNotFound', 'The specified object was not found in the store.')])
    key = user.lower()
    if key not in CATEGORY_LISTS:
        # the second test account has no list yet: Outlook was never used there
        CATEGORY_LISTS[key] = DEFAULT_CATEGORIES if key.endswith('@societe-a.fr') else None
    if op == 'GetUserConfiguration':
        xml = CATEGORY_LISTS[key]
        if xml is None:
            return wrap(op, [err(op, 'ErrorItemNotFound', 'The specified object was not found in the store.')])
        return wrap(op, [ok(op, '<m:UserConfiguration><t:UserConfigurationName Name="CategoryList"><t:FolderId Id="F-CALENDAR"/></t:UserConfigurationName>'
                            '<t:XmlData>%s</t:XmlData></m:UserConfiguration>' % base64.b64encode(xml.encode('utf-8')).decode())])
    if (op == 'CreateUserConfiguration') != (CATEGORY_LISTS[key] is None):
        return wrap(op, [err(op, 'ErrorItemSave' if op == 'CreateUserConfiguration' else 'ErrorItemNotFound', 'wrong operation')])
    data = el.findtext('.//t:XmlData', default='', namespaces=NS)
    CATEGORY_LISTS[key] = base64.b64decode(data).decode('utf-8')
    ET.fromstring(CATEGORY_LISTS[key].encode('utf-8'))  # must stay valid XML
    return wrap(op, [ok(op)])


def handle(op, el, user):
    global CURRENT_USER
    CURRENT_USER = user
    if op == 'Subscribe':
        sub = el.find('m:PullSubscriptionRequest', NS)
        if sub is None or [f.get('Id') for f in sub.findall('t:FolderIds/t:DistinguishedFolderId', NS)] != ['inbox']:
            return wrap(op, [err(op, 'ErrorInvalidSubscriptionRequest', 'inbox pull subscriptions only')])
        sid = 'SUB-%d' % next(COUNTER)
        SUBSCRIPTIONS[sid] = True
        return wrap(op, [ok(op, '<m:SubscriptionId>%s</m:SubscriptionId><m:Watermark>W%d</m:Watermark>' % (sid, len(INBOX_EVENTS)))])
    if op == 'GetEvents':
        sid = el.findtext('m:SubscriptionId', default='', namespaces=NS)
        if sid not in SUBSCRIPTIONS:
            return wrap(op, [err(op, 'ErrorSubscriptionNotFound', 'The specified subscription was not found.')])
        start = int(el.findtext('m:Watermark', default='W0', namespaces=NS)[1:])
        new = INBOX_EVENTS[start:]
        events = ''.join('<t:NewMailEvent><t:Watermark>W%d</t:Watermark><t:TimeStamp>%s</t:TimeStamp><t:ItemId Id="%s" ChangeKey="CK"/>'
                         '<t:ParentFolderId Id="F-INBOX" ChangeKey="AQ"/></t:NewMailEvent>' % (start + i + 1, now(0), mid) for i, mid in enumerate(new)) \
            or '<t:StatusEvent><t:Watermark>W%d</t:Watermark></t:StatusEvent>' % start
        return wrap(op, [ok(op, '<m:Notification><t:SubscriptionId>%s</t:SubscriptionId><t:PreviousWatermark>W%d</t:PreviousWatermark>'
                                '<t:MoreEvents>false</t:MoreEvents>%s</m:Notification>' % (sid, start, events))])

    if op == 'FindConversation':
        fid = folder_id(el.find('m:ParentFolderId', NS)[0])
        if fid is None:
            return '<m:FindConversationResponse ResponseClass="Error" xmlns:m="%s" xmlns:t="%s"><m:MessageText>not found</m:MessageText><m:ResponseCode>ErrorFolderNotFound</m:ResponseCode><m:DescriptiveLinkKey>0</m:DescriptiveLinkKey></m:FindConversationResponse>' % (NS['m'], NS['t'])
        view = el.find('m:IndexedPageItemView', NS)
        offset, limit = int(view.get('Offset', '0')), int(view.get('MaxEntriesReturned', '50'))
        groups = {}
        for m in MESSAGES.values():
            if m['folder'] == fid:
                groups.setdefault(conversation_id(m), []).append(m)
        convs = sorted(groups.items(), key=lambda kv: max(x['date'] for x in kv[1]), reverse=True)
        out = ''
        for cid, msgs in convs[offset:offset + limit]:
            msgs.sort(key=lambda x: x['date'], reverse=True)
            senders = []
            for x in msgs:
                if x['sender'][0] not in senders:
                    senders.append(x['sender'][0])
            import re as _re
            topic = _re.sub(r'^\s*((re|tr|fw|fwd)\s*:\s*)+', '', msgs[-1]['subject'], flags=_re.I).strip()
            out += ('<t:Conversation><t:ConversationId Id="%s"/><t:ConversationTopic>%s</t:ConversationTopic>'
                    '<t:UniqueSenders>%s</t:UniqueSenders><t:LastDeliveryTime>%s</t:LastDeliveryTime><t:HasAttachments>%s</t:HasAttachments>'
                    '<t:MessageCount>%d</t:MessageCount><t:UnreadCount>%d</t:UnreadCount><t:ItemIds>%s</t:ItemIds></t:Conversation>'
                    % (cid, esc(topic), ''.join('<t:String>%s</t:String>' % esc(n) for n in senders), msgs[0]['date'],
                       'true' if any(not ATTACHMENTS[a].get('inline') for x in msgs for a in x['attachments']) else 'false',
                       len(msgs), sum(1 for x in msgs if not x['is_read']),
                       ''.join('<t:ItemId Id="%s" ChangeKey="%s"/>' % (x['id'], x['ck']) for x in msgs)))
        return ('<m:FindConversationResponse ResponseClass="Success" xmlns:m="%s" xmlns:t="%s"><m:ResponseCode>NoError</m:ResponseCode>'
                '<m:Conversations>%s</m:Conversations><m:TotalConversationsInView>%d</m:TotalConversationsInView><m:IndexedOffset>%d</m:IndexedOffset></m:FindConversationResponse>'
                % (NS['m'], NS['t'], out, len(convs), offset + min(limit, max(0, len(convs) - offset))))

    if op == 'GetConversationItems':
        cid = el.find('m:Conversations/t:Conversation/t:ConversationId', NS).get('Id')
        skip = el.find('m:FoldersToIgnore', NS)
        ignore = {folder_id(f) for f in (list(skip) if skip is not None else [])}
        msgs = sorted((m for m in MESSAGES.values() if conversation_id(m) == cid and m['folder'] not in ignore), key=lambda m: m['date'])
        nodes = ''.join('<t:ConversationNode><t:InternetMessageId>&lt;%s@fake&gt;</t:InternetMessageId><t:Items>%s</t:Items></t:ConversationNode>'
                        % (m['id'], item_summary_xml(m, True)) for m in msgs)
        return wrap(op, [ok(op, '<m:Conversation><t:ConversationId Id="%s"/><t:ConversationNodes>%s</t:ConversationNodes></m:Conversation>' % (cid, nodes))])
    r = handle_user_configuration(op, el, user)
    if r is not None:
        return r
    r = tasks.handle(op, el)
    if r is not None:
        return r
    r = rules.handle(op, el, user, lambda fid: any(f[0] == fid for f in FOLDERS))
    if r is not None:
        return r
    # the folders of a shared mailbox are mail folders (not a colleague's calendar or contacts)
    in_shared = any((e.text or '').lower() in SHARED_MAILBOXES for e in el.iter(T + 'EmailAddress'))
    r = None if in_shared and op in ('FindFolder', 'GetFolder') else pim.handle(op, el, user, CURRENT_TZ, MESSAGES, move_message)
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
                if any(u.get('FieldURI') == 'folder:UnreadCount' for u in el.iter(T + 'FieldURI')):
                    quota += '<t:UnreadCount>%d</t:UnreadCount>' % counts(fid)[1]
                f = next((x for x in FOLDERS if x[0] == fid), None)
                if f is not None and el.findtext('m:FolderShape/t:BaseShape', default='', namespaces=NS) == 'Default':
                    # Default shape: name and counts, like Exchange
                    msgs.append(ok(op, '<m:Folders>%s</m:Folders>' % folder_xml(f)))
                    continue
                msgs.append(ok(op, '<m:Folders><t:Folder><t:FolderId Id="%s" ChangeKey="AQ"/>%s</t:Folder></m:Folders>' % (fid, quota)))
        return wrap(op, msgs)

    if op in ('CreateFolder', 'UpdateFolder', 'MoveFolder', 'DeleteFolder'):
        return manage_folder(op, el)

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
        root = folder_id(el.find('m:ParentFolderIds', NS)[0])
        if root is None:
            return wrap(op, [err(op, 'ErrorFolderNotFound', 'The specified folder could not be found in the store.')])
        if el.get('Traversal') == 'Shallow':
            # only the folders right under it
            mine = [f for f in FOLDERS if f[2] == root and f[3] != 'msgfolderroot']
        elif any(f[0] == root and f[3] == 'msgfolderroot' for f in FOLDERS):
            mine = [f for f in FOLDERS if f[3] != 'msgfolderroot' and root_of(f[0]) == root]
        else:
            # under a folder (a delegate's inbox): its sub-folders
            def below(pid):
                out = []
                for f in FOLDERS:
                    if f[2] == pid:
                        out += [f] + below(f[0])
                return out
            mine = below(root)
        folders = ''.join(folder_xml(f, with_size) for f in mine) + (cal if root == 'F-ROOT' else '')
        return wrap(op, [ok(op, '<m:RootFolder TotalItemsInView="%d" IncludesLastItemInRange="true"><t:Folders>%s</t:Folders></m:RootFolder>'
                            % (len(FOLDERS), folders))])

    if op == 'FindItem':
        view = el.find('m:IndexedPageItemView', NS)
        offset, limit = int(view.get('Offset', 0)), int(view.get('MaxEntriesReturned', 50))
        q = (el.findtext('m:QueryString', default='', namespaces=NS) or '').lower()
        with_folder = any(u.get('FieldURI') == 'item:ParentFolderId' for u in el.iter(T + 'FieldURI'))
        # the AQS keywords the webmail uses; the other words are searched in the text
        conds, words = [], []
        import re as _re
        for cat in _re.findall(r'category:"([^"]*)"', q):
            conds.append(lambda m, cat=cat: any(c.lower() == cat for c in m.get('categories') or []))
        q = _re.sub(r'category:"[^"]*"', ' ', q)
        for tok in q.split():
            if tok == 'isread:false':
                conds.append(lambda m: not m['is_read'])
            elif tok == 'hasattachment:true':
                conds.append(lambda m: any(not ATTACHMENTS[a].get('inline') for a in m['attachments']))
            elif tok == 'isflagged:true':
                conds.append(lambda m: m['flagged'])
            elif tok.startswith('received>='):
                since = tok[len('received>='):]
                conds.append(lambda m, since=since: m['date'][:10] >= since)
            else:
                words.append(tok)
        text = ' '.join(words)
        # Restriction used for notifications: received after a date, unread
        after = el.find('m:Restriction//t:IsGreaterThanOrEqualTo/t:FieldURIOrConstant/t:Constant', NS)
        if after is not None:
            conds.append(lambda m, d=after.get('Value'): m['date'] >= d)
            # both test accounts share this fake inbox: for new messages, each only
            # sees what was sent to it, as with two real mailboxes
            conds.append(lambda m: any(e.lower() == user.lower() for _, e in m['to'] + m['cc']))
        # received after a date (strictly), with attachments (« classer les pièces jointes »)
        later = el.find('m:Restriction//t:IsGreaterThan/t:FieldURIOrConstant/t:Constant', NS)
        if later is not None:
            conds.append(lambda m, d=later.get('Value'): m['date'] > d)
            conds.append(lambda m: any(e.lower() == user.lower() for _, e in m['to'] + m['cc']))
        for eq in el.findall('m:Restriction//t:IsEqualTo', NS):
            if eq.find('t:FieldURI', NS) is not None and eq.find('t:FieldURI', NS).get('FieldURI') == 'item:HasAttachments':
                conds.append(lambda m: any(not ATTACHMENTS[a].get('inline') for a in m['attachments']))
        unread = el.find('m:Restriction//t:IsEqualTo[t:FieldURI]', NS)
        if unread is not None and unread.find('t:FieldURI', NS).get('FieldURI') == 'message:IsRead':
            conds.append(lambda m: not m['is_read'])
        out = []
        for parent in el.find('m:ParentFolderIds', NS):
            fid = folder_id(parent)
            if fid is None or fid not in {f[0] for f in FOLDERS}:
                out.append(err(op, 'ErrorFolderNotFound', 'Folder not found'))
                continue
            msgs = [m for m in MESSAGES.values() if m['folder'] == fid and all(c(m) for c in conds)]
            if text:
                msgs = [m for m in msgs if text in m['subject'].lower() or text in m['body'].lower() or text in m['sender'][0].lower()]
            msgs.sort(key=lambda m: m['date'], reverse=True)
            # « Trier par »: the first field asked (then by date, newest first, as already sorted)
            first = el.find('m:SortOrder/t:FieldOrder', NS)
            if first is not None:
                uri = first.find('t:FieldURI', NS)
                ext = first.find('t:ExtendedFieldURI', NS)
                key = {'item:Subject': lambda m: m['subject'].lower(), 'item:Size': size_of,
                       'item:DateTimeReceived': lambda m: m['date']}.get(uri.get('FieldURI') if uri is not None else '')
                if ext is not None and ext.get('PropertyTag') == '0x0042':
                    key = lambda m: m['sender'][0].lower()
                if key is not None:
                    msgs.sort(key=key, reverse=first.get('Order') == 'Descending')
            page = msgs[offset:offset + limit]
            last = offset + limit >= len(msgs)
            out.append(ok(op, '<m:RootFolder IndexedPagingOffset="%d" TotalItemsInView="%d" IncludesLastItemInRange="%s"><t:Items>%s</t:Items></m:RootFolder>'
                          % (offset + len(page), len(msgs), 'true' if last else 'false', ''.join(item_summary_xml(m, with_folder) for m in page))))
        return wrap(op, out)

    if op == 'GetItem':
        shape = el.findtext('m:ItemShape/t:BaseShape', default='', namespaces=NS)
        msgs = []
        for i in el.find('m:ItemIds', NS):
            m = MESSAGES.get(i.get('Id'))
            if not m:
                msgs.append(err(op, 'ErrorItemNotFound', 'The specified object was not found in the store.'))
            elif el.findtext('m:ItemShape/t:IncludeMimeContent', default='', namespaces=NS) == 'true':
                # the whole message, as an .eml (what Exchange gives with IncludeMimeContent)
                msgs.append(ok(op, '<m:Items><t:Message><t:MimeContent CharacterSet="UTF-8">%s</t:MimeContent>'
                                   '<t:ItemId Id="%s" ChangeKey="%s"/><t:Subject>%s</t:Subject></t:Message></m:Items>'
                               % (base64.b64encode(mime_of(m)).decode(), m['id'], m['ck'], esc(m['subject']))))
            elif el.find('m:ItemShape/t:AdditionalProperties/t:ExtendedFieldURI[@PropertyTag="0x007D"]', NS) is not None:
                # the Internet headers (PR_TRANSPORT_MESSAGE_HEADERS): none for a message written in the mailbox
                raw = headers_of(m)
                prop = ('<t:ExtendedProperty><t:ExtendedFieldURI PropertyTag="0x7d" PropertyType="String"/><t:Value>%s</t:Value></t:ExtendedProperty>'
                        % esc(raw)) if raw else ''
                msgs.append(ok(op, '<m:Items><t:Message><t:ItemId Id="%s" ChangeKey="%s"/>%s</t:Message></m:Items>' % (m['id'], m['ck'], prop)))
            elif shape == 'IdOnly' and el.find('m:ItemShape/t:AdditionalProperties/t:FieldURI', NS) is None \
                    and el.find('m:ItemShape/t:AdditionalProperties/t:ExtendedFieldURI[@DistinguishedPropertySetId="InternetHeaders"]', NS) is not None:
                # only the List-Unsubscribe headers
                prop = ('<t:ExtendedProperty><t:ExtendedFieldURI DistinguishedPropertySetId="InternetHeaders" PropertyName="list-unsubscribe" PropertyType="String"/>'
                        '<t:Value>%s</t:Value></t:ExtendedProperty>' % esc(m['list_unsubscribe'])) if m.get('list_unsubscribe') else ''
                msgs.append(ok(op, '<m:Items><t:Message><t:ItemId Id="%s" ChangeKey="%s"/>%s</t:Message></m:Items>' % (m['id'], m['ck'], prop)))
            elif shape == 'IdOnly' and el.find('m:ItemShape/t:AdditionalProperties', NS) is None:
                msgs.append(ok(op, '<m:Items><t:Message><t:ItemId Id="%s" ChangeKey="%s"/></t:Message></m:Items>' % (m['id'], m['ck'])))
            else:
                msgs.append(ok(op, '<m:Items>%s</m:Items>' % item_full_xml(m)))
        return wrap(op, msgs)

    if op == 'MarkAllItemsAsRead':
        read = el.findtext('m:ReadFlag', default='true', namespaces=NS) == 'true'
        for f in el.find('m:FolderIds', NS):
            fid = folder_id(f)
            for m in MESSAGES.values():
                if m['folder'] == fid:
                    m['is_read'] = read
        return wrap(op, [ok(op, '')])

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
                # drafts: subject, body, importance, recipients
                uri = (upd.find('t:FieldURI', NS) if upd.find('t:FieldURI', NS) is not None else ET.Element('x')).get('FieldURI', '')
                rcpt = {'message:ToRecipients': 'to', 'message:CcRecipients': 'cc', 'message:BccRecipients': 'bcc'}
                if upd.tag == T + 'DeleteItemField' and uri in rcpt:
                    m[rcpt[uri]] = []
                    continue
                if uri == 'item:Categories':
                    m['categories'] = [] if upd.tag == T + 'DeleteItemField' else [x.text or '' for x in upd.findall('t:Message/t:Categories/t:String', NS)]
                    continue
                item = upd.find('t:Message', NS)
                if item is not None:
                    if uri == 'item:Subject':
                        m['subject'] = item.findtext('t:Subject', default='', namespaces=NS)
                    elif uri == 'item:Body':
                        m['body'] = item.findtext('t:Body', default='', namespaces=NS)
                    elif uri == 'item:Importance':
                        m['importance'] = item.findtext('t:Importance', default='Normal', namespaces=NS)
                    elif uri in rcpt:
                        m[rcpt[uri]] = recipients(item, uri[8:])
                if item is not None and item.find('t:ExtendedProperty', NS) is not None:
                    m['send_at'] = item.findtext('t:ExtendedProperty/t:Value', default='', namespaces=NS)
                if item is not None and item.find('t:IsReadReceiptRequested', NS) is not None:
                    m['read_receipt'] = item.findtext('t:IsReadReceiptRequested', namespaces=NS) == 'true'
                r = upd.findtext('.//t:IsRead', default=None, namespaces=NS)
                if r is not None:
                    m['is_read'] = r == 'true'
                fs = upd.findtext('.//t:FlagStatus', default=None, namespaces=NS)
                if fs is not None:
                    m['flagged'] = fs == 'Flagged'
                    # the due date goes with the flag (Exchange drops it when the flag goes)
                    m['flag_due'] = upd.findtext('.//t:Flag/t:DueDate', default=None, namespaces=NS) if fs == 'Flagged' else None
            m['ck'] = 'CK-%d' % next(COUNTER)
            msgs.append(ok(op, '<m:Items><t:Message><t:ItemId Id="%s" ChangeKey="%s"/></t:Message></m:Items><m:ConflictResults><t:Count>0</t:Count></m:ConflictResults>' % (m['id'], m['ck'])))
        return wrap(op, msgs)

    if op == 'ApplyConversationAction':
        msgs = []
        for act in el.find('m:ConversationActions', NS):
            action = act.findtext('t:Action', namespaces=NS)
            cid = act.find('t:ConversationId', NS).get('Id')
            mine = [m for m in MESSAGES.values() if conversation_id(m) == cid and m['folder'].startswith('F-')]
            if action == 'AlwaysDelete':
                if act.findtext('t:EnableAlwaysDelete', namespaces=NS) == 'true':
                    IGNORED.add(cid)
                else:
                    IGNORED.discard(cid)
                if act.findtext('t:ProcessRightAway', namespaces=NS) == 'true':
                    for m in mine:
                        if m['folder'] not in ('F-DELETED', 'F-DRAFTS', 'F-SENT'):
                            m['folder'] = 'F-DELETED'
            elif action == 'Move':
                ctx = act.find('t:ContextFolderId', NS)
                src = folder_id(ctx[0]) if ctx is not None else None
                dst = folder_id(act.find('t:DestinationFolderId', NS)[0])
                for m in mine:
                    if src is None or m['folder'] == src:
                        m['folder'] = dst
            msgs.append(ok(op, ''))
        return wrap(op, msgs)

    if op == 'MarkAsJunk':
        junk = el.get('IsJunk') == 'true'
        target = ROLE_TO_ID['junkemail' if junk else 'inbox']
        out = []
        for i in el.find('m:ItemIds', NS):
            m = MESSAGES.get(i.get('Id'))
            if not m:
                out.append(err(op, 'ErrorItemNotFound', 'not found'))
                continue
            (BLOCKED_SENDERS if junk else SAFE_SENDERS).add(m['sender'][1].lower())
            (SAFE_SENDERS if junk else BLOCKED_SENDERS).discard(m['sender'][1].lower())
            if el.get('MoveItem') == 'true':
                m['folder'] = target
            out.append(ok(op, '<m:MovedItemId Id="%s" ChangeKey="%s"/>' % (m['id'], m['ck'])))
        return wrap(op, out)

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
            # (Exchange gives the id of the message in its new folder)
            msgs.append(ok(op, ('<m:Items><t:Message><t:ItemId Id="%s" ChangeKey="%s"/></t:Message></m:Items>' % (m['id'], m['ck'])
                                if not hard else '<m:Items/>') if op == 'MoveItem' else ''))
        return wrap(op, msgs)

    if op == 'CreateItem':
        disposition = el.get('MessageDisposition')
        msgs = []
        for it in el.find('m:Items', NS):
            tag = it.tag.replace(T, '')
            mime = it.findtext('t:MimeContent', default='', namespaces=NS)
            if tag == 'Message' and mime:
                # an imported .eml: a received message in the folder given
                from email import message_from_bytes, policy
                from email.utils import parseaddr
                parsed = message_from_bytes(base64.b64decode(mime), policy=policy.default)
                saved = el.find('m:SavedItemFolderId', NS)
                target = folder_id(saved[0]) if saved is not None and len(saved) else 'F-DRAFTS'
                part = parsed.get_body(preferencelist=('html', 'plain'))
                text = part.get_content() if part is not None else ''
                body = text if part is not None and part.get_content_subtype() == 'html' else '<p>%s</p>' % esc(text)
                name, addr = parseaddr(str(parsed.get('From', '')))
                flags = it.find('t:ExtendedProperty/t:Value', NS)
                mid = add_message(target, str(parsed.get('Subject', '')), (name or addr, addr), body, 0,
                                  is_read=flags is not None and flags.text == '1')
                msgs.append(ok(op, '<m:Items><t:Message><t:ItemId Id="%s" ChangeKey="%s"/></t:Message></m:Items>' % (mid, MESSAGES[mid]['ck'])))
                continue
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
                                        content=base64.b64decode(a.findtext('t:Content', default='', namespaces=NS)),
                                        inline=a.findtext('t:IsInline', default='false', namespaces=NS) == 'true',
                                        content_id=a.findtext('t:ContentId', default='', namespaces=NS))
                atts.append(aid)
            if tag == 'ForwardItem':
                atts += list(ref['attachments'])
            folder = 'F-SENT' if disposition == 'SendAndSaveCopy' else 'F-DRAFTS'
            sender = ('Utilisateur', user)
            as_ = (it.findtext('t:From/t:Mailbox/t:EmailAddress', default='', namespaces=NS) or '').lower()
            if as_ and as_ != user.lower():
                mb = SHARED_MAILBOXES.get(as_)
                if not mb or user.lower() not in mb['allowed']:
                    msgs.append(err(op, 'ErrorSendAsDenied', 'The user account which was used to submit this request does not have the right to send mail on behalf of the specified sending account.'))
                    continue
                sender = (mb['name'], as_)
            mid = add_message(folder, subject, sender, body, 0, is_read=True, to=to, cc=recipients(it, 'CcRecipients'),
                              importance=it.findtext('t:Importance', default='Normal', namespaces=NS))
            MESSAGES[mid]['attachments'] = atts
            MESSAGES[mid]['bcc'] = recipients(it, 'BccRecipients')
            if disposition == 'SendAndSaveCopy':
                deliver_to_self(MESSAGES[mid], user)
            items = '<m:Items/>' if disposition == 'SendAndSaveCopy' else '<m:Items><t:Message><t:ItemId Id="%s" ChangeKey="%s"/></t:Message></m:Items>' % (mid, MESSAGES[mid]['ck'])
            msgs.append(ok(op, items))
        return wrap(op, msgs)

    if op == 'DeleteAttachment':
        out = []
        for a in el.findall('m:AttachmentIds/t:AttachmentId', NS):
            aid = a.get('Id')
            parent = next((m for m in MESSAGES.values() if aid in m['attachments']), None)
            if parent is None:
                out.append(err(op, 'ErrorInvalidAttachmentId', 'The attachment id is invalid.'))
                continue
            parent['attachments'].remove(aid)
            ATTACHMENTS.pop(aid, None)
            parent['ck'] = 'CK-%d' % next(COUNTER)
            out.append(ok(op, '<m:RootItemId RootItemId="%s" RootItemChangeKey="%s"/>' % (parent['id'], parent['ck'])))
        return wrap(op, out)

    if op == 'CreateAttachment':
        parent = MESSAGES.get(el.find('m:ParentItemId', NS).get('Id'))
        if parent is None:
            return wrap(op, [err(op, 'ErrorItemNotFound', 'not found')])
        out = []
        for a in el.find('m:Attachments', NS):
            aid = 'ATT-%d' % next(COUNTER)
            ATTACHMENTS[aid] = dict(id=aid, name=a.findtext('t:Name', namespaces=NS), content_type=a.findtext('t:ContentType', default='application/octet-stream', namespaces=NS),
                                    content=base64.b64decode(a.findtext('t:Content', default='', namespaces=NS)),
                                        inline=a.findtext('t:IsInline', default='false', namespaces=NS) == 'true',
                                        content_id=a.findtext('t:ContentId', default='', namespaces=NS))
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
            elif m.get('send_at') and m['send_at'] > now():
                m['folder'] = 'F-OUTBOX'  # Exchange keeps it there until the time comes
                msgs.append(ok(op))
            else:
                m['folder'] = 'F-SENT'
                deliver_to_self(m, user)
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
        settings = [s.text for s in root.iter('{%s}Setting' % ad)]
        if not settings or self.headers.get('SOAPAction', '').strip('"') != ad + '/Autodiscover/GetUserSettings':
            self.send(400, '')
            return
        # the EWS address of a domain served here (societe-c.fr: found by Autodiscover, not in the admin table)
        values = {'PublicFolderInformation': pim.PF_MAILBOX if mailbox else '',
                  'ExternalEwsUrl': 'https://ex3.fake.test:8443/EWS/Exchange.asmx'}
        found = ''.join('<UserSetting xmlns:i="http://www.w3.org/2001/XMLSchema-instance" i:type="StringSetting"><Name>%s</Name><Value>%s</Value></UserSetting>'
                        % (n, values[n]) for n in settings if n in values)
        body = ('<s:Envelope xmlns:s="http://schemas.xmlsoap.org/soap/envelope/"><s:Body>'
                '<GetUserSettingsResponseMessage xmlns="%s"><Response><ErrorCode>NoError</ErrorCode><UserResponses><UserResponse>'
                '<ErrorCode>NoError</ErrorCode><UserSettings>%s</UserSettings></UserResponse></UserResponses>'
                '</Response></GetUserSettingsResponseMessage></s:Body></s:Envelope>' % (ad, found))
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
