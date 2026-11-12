"""Inbox rules of the fake EWS server: GetInboxRules / UpdateInboxRules, and
the rules run on messages arriving in the inbox (subject, sender, from)."""

import itertools
import xml.etree.ElementTree as ET

NS = {
    'm': 'http://schemas.microsoft.com/exchange/services/2006/messages',
    't': 'http://schemas.microsoft.com/exchange/services/2006/types',
}
T = '{%s}' % NS['t']
ET.register_namespace('t', NS['t'])
ET.register_namespace('m', NS['m'])

RULES = {}          # mailbox -> [t:Rule element] by priority
BLOB = {}           # mailbox -> Outlook still has its own copy of the rules
COUNTER = itertools.count(1)


def seed(mailbox):
    """A rule made in Outlook: a size condition and an exception the webmail does not edit."""
    rule = ET.fromstring(
        '<t:Rule xmlns:t="%s"><t:RuleId>RULE-OUTLOOK</t:RuleId><t:DisplayName>Grosses pièces jointes</t:DisplayName>'
        '<t:Priority>1</t:Priority><t:IsEnabled>true</t:IsEnabled><t:IsNotSupported>false</t:IsNotSupported><t:IsInError>false</t:IsInError>'
        '<t:Conditions><t:WithinSizeRange><t:MinimumSize>5242880</t:MinimumSize></t:WithinSizeRange></t:Conditions>'
        '<t:Exceptions><t:SentOnlyToMe>true</t:SentOnlyToMe></t:Exceptions>'
        '<t:Actions><t:AssignCategories><t:String>Lourd</t:String></t:AssignCategories></t:Actions></t:Rule>' % NS['t'])
    RULES[mailbox] = [rule]
    BLOB[mailbox] = True


def renumber(rules):
    for i, r in enumerate(rules):
        r.find('t:Priority', NS).text = str(i + 1)


def response(op, inner='', error=None):
    if error:
        return ('<m:%sResponse ResponseClass="Error" xmlns:m="%s" xmlns:t="%s"><m:MessageText>%s</m:MessageText><m:ResponseCode>%s</m:ResponseCode>%s</m:%sResponse>'
                % (op, NS['m'], NS['t'], error[1], error[0], inner, op))
    return ('<m:%sResponse ResponseClass="Success" xmlns:m="%s" xmlns:t="%s"><m:ResponseCode>NoError</m:ResponseCode>%s</m:%sResponse>'
            % (op, NS['m'], NS['t'], inner, op))


def validation_error(index, field, message, value):
    return ('<m:RuleOperationErrors><t:RuleOperationError><t:OperationIndex>%d</t:OperationIndex><t:ValidationErrors><t:Error>'
            '<t:FieldURI>%s</t:FieldURI><t:ErrorCode>InvalidValue</t:ErrorCode><t:ErrorMessage>%s</t:ErrorMessage><t:FieldValue>%s</t:FieldValue>'
            '</t:Error></t:ValidationErrors></t:RuleOperationError></m:RuleOperationErrors>' % (index, field, message, value))


def handle(op, el, user, folder_exists):
    if op not in ('GetInboxRules', 'UpdateInboxRules'):
        return None
    mailbox = (el.findtext('m:MailboxSmtpAddress', default='', namespaces=NS) or user).lower()
    if mailbox != user.lower():
        return response(op, error=('ErrorAccessDenied', 'Access is denied.'))
    if mailbox not in RULES:
        seed(mailbox)
    rules = RULES[mailbox]
    if op == 'GetInboxRules':
        inner = '<m:OutlookRuleBlobExists>%s</m:OutlookRuleBlobExists><m:InboxRules>%s</m:InboxRules>' % (
            'true' if BLOB[mailbox] else 'false', ''.join(ET.tostring(r, encoding='unicode') for r in rules))
        return response(op, inner)

    if BLOB[mailbox] and el.findtext('m:RemoveOutlookRuleBlob', default='false', namespaces=NS) != 'true':
        return response(op, error=('ErrorOutlookRuleBlobExists', 'Outlook rules blob exists.'))
    new = list(rules)
    for i, operation in enumerate(el.find('m:Operations', NS)):
        kind = operation.tag[len(T):]
        if kind == 'DeleteRuleOperation':
            rid = operation.findtext('t:RuleId', namespaces=NS)
            if not any(r.findtext('t:RuleId', namespaces=NS) == rid for r in new):
                return response(op, validation_error(i, 'RuleId', 'The rule was not found.', rid), ('ErrorInboxRulesValidationError', 'validation'))
            new = [r for r in new if r.findtext('t:RuleId', namespaces=NS) != rid]
            continue
        rule = operation.find('t:Rule', NS)
        for action in ('MoveToFolder', 'CopyToFolder'):
            f = rule.find('t:Actions/t:%s/t:FolderId' % action, NS)
            if f is not None and not folder_exists(f.get('Id')):
                return response(op, validation_error(i, 'Action:' + action, 'Le dossier n\'existe pas.', f.get('Id')), ('ErrorInboxRulesValidationError', 'validation'))
        priority = int(rule.findtext('t:Priority', default='1', namespaces=NS))
        if kind == 'CreateRuleOperation':
            if rule.find('t:RuleId', NS) is not None:
                return response(op, error=('ErrorInvalidRequest', 'RuleId is set by the server.'))
            rid = ET.Element(T + 'RuleId')
            rid.text = 'RULE-%d' % next(COUNTER)
            rule.insert(0, rid)
        else:
            rid = rule.findtext('t:RuleId', namespaces=NS)
            old = [r for r in new if r.findtext('t:RuleId', namespaces=NS) == rid]
            if not old:
                return response(op, validation_error(i, 'RuleId', 'The rule was not found.', rid), ('ErrorInboxRulesValidationError', 'validation'))
            new.remove(old[0])
            if rule.find('t:IsNotSupported', NS) is not None:
                return response(op, error=('ErrorInvalidRequest', 'IsNotSupported is read-only.'))
        new.insert(max(0, min(priority - 1, len(new))), rule)
    renumber(new)
    RULES[mailbox] = new
    BLOB[mailbox] = False
    return response(op)


def apply(mailbox, m, move):
    """Run the enabled rules on a message just put in the inbox (the conditions the webmail edits)."""
    for rule in RULES.get(mailbox.lower(), []):
        if rule.findtext('t:IsEnabled', namespaces=NS) != 'true':
            continue
        cond = rule.find('t:Conditions', NS)
        if cond is None or rule.find('t:Exceptions', NS) is not None and len(rule.find('t:Exceptions', NS)):
            continue
        matched = True
        for c in cond:
            name = c.tag[len(T):]
            words = [s.text.lower() for s in c.findall('t:String', NS) if s.text]
            addresses = [a.findtext('t:EmailAddress', default='', namespaces=NS).lower() for a in c.findall('t:Address', NS)]
            if name == 'ContainsSubjectStrings':
                matched = matched and any(w in m['subject'].lower() for w in words)
            elif name == 'ContainsSenderStrings':
                matched = matched and any(w in (m['sender'][0] + ' ' + m['sender'][1]).lower() for w in words)
            elif name == 'FromAddresses':
                matched = matched and m['sender'][1].lower() in addresses
            else:
                matched = False
        if not matched:
            continue
        actions = rule.find('t:Actions', NS)
        if actions.findtext('t:MarkAsRead', namespaces=NS) == 'true':
            m['is_read'] = True
        target = actions.find('t:MoveToFolder/t:FolderId', NS)
        if target is not None:
            move(m, target.get('Id'))
        elif actions.findtext('t:Delete', namespaces=NS) == 'true':
            move(m, 'F-DELETED')
        if actions.findtext('t:StopProcessingRules', namespaces=NS) == 'true':
            break
