"""Tasks (the « Tâches » folder) of the fake EWS server: FindItem, GetItem,
CreateItem, UpdateItem and DeleteItem on items whose id starts with TASK-."""

import datetime as dt
import html
import itertools

NS = {
    'm': 'http://schemas.microsoft.com/exchange/services/2006/messages',
    't': 'http://schemas.microsoft.com/exchange/services/2006/types',
}
T = '{%s}' % NS['t']
COUNTER = itertools.count(1)
TASKS = {}  # id -> dict


def esc(s):
    return html.escape(str(s), quote=True)


def now():
    return dt.datetime.now(dt.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')


def outlook_due(days):
    """A due date as Outlook stores it: midnight in Paris, in UTC (23:00Z the day before)."""
    d = dt.date.today() + dt.timedelta(days=days)
    return (dt.datetime(d.year, d.month, d.day) - dt.timedelta(hours=1)).strftime('%Y-%m-%dT%H:%M:%SZ')


def add(subject, due=None, status='NotStarted', importance='Normal', body=''):
    tid = 'TASK-%d' % next(COUNTER)
    TASKS[tid] = {'id': tid, 'subject': subject, 'due': due, 'status': status, 'importance': importance, 'body': body,
                  'percent': 100 if status == 'Completed' else 0, 'completed': now() if status == 'Completed' else None,
                  'created': now()}
    return tid


def seed():
    add('Relancer le cabinet comptable', due=outlook_due(-2), body='Bilan 2025 à récupérer')
    add('Renouveler le nom de domaine', due=outlook_due(1), importance='High')
    add('Commander des cartouches d\'encre', status='InProgress')
    add('Envoyer les vœux aux clients', status='Completed')


seed()


def wrap(op, msgs):
    return ('<m:%sResponse xmlns:m="%s" xmlns:t="%s"><m:ResponseMessages>%s</m:ResponseMessages></m:%sResponse>'
            % (op, NS['m'], NS['t'], ''.join(msgs), op))


def ok(op, inner=''):
    return '<m:%sResponseMessage ResponseClass="Success"><m:ResponseCode>NoError</m:ResponseCode>%s</m:%sResponseMessage>' % (op, inner, op)


def err(op, code, text):
    return ('<m:%sResponseMessage ResponseClass="Error"><m:MessageText>%s</m:MessageText><m:ResponseCode>%s</m:ResponseCode>'
            '<m:DescriptiveLinkKey>0</m:DescriptiveLinkKey></m:%sResponseMessage>' % (op, esc(text), code, op))


def task_xml(t, detail=False):
    reminder = ('<t:ReminderDueBy>%s</t:ReminderDueBy><t:ReminderIsSet>true</t:ReminderIsSet>' % t['reminder']
                if t.get('reminder') else '<t:ReminderIsSet>false</t:ReminderIsSet>')
    return ('<t:Task><t:ItemId Id="%s" ChangeKey="CK"/><t:Subject>%s</t:Subject>%s<t:Importance>%s</t:Importance>%s'
            '<t:DateTimeCreated>%s</t:DateTimeCreated>%s%s<t:IsRecurring>false</t:IsRecurring>'
            '<t:PercentComplete>%d</t:PercentComplete><t:Status>%s</t:Status></t:Task>'
            % (t['id'], esc(t['subject']), '<t:Body BodyType="Text">%s</t:Body>' % esc(t['body']) if detail else '',
               t['importance'], reminder, t['created'], '<t:CompleteDate>%s</t:CompleteDate>' % t['completed'] if t['completed'] else '',
               '<t:DueDate>%s</t:DueDate>' % t['due'] if t['due'] else '', t['percent'], t['status']))


def item_ids(el):
    return [i.get('Id') for i in el.findall('m:ItemIds/t:ItemId', NS)]


def fields(task_el):
    """The fields of a t:Task element (CreateItem or SetItemField)."""
    out = {}
    for c in task_el:
        out[c.tag.replace(T, '')] = c.text or ''
    return out


def apply(t, f):
    if 'Subject' in f:
        t['subject'] = f['Subject']
    if 'Body' in f:
        t['body'] = f['Body']
    if 'Importance' in f:
        t['importance'] = f['Importance']
    if 'DueDate' in f:
        t['due'] = f['DueDate']
    if 'ReminderDueBy' in f:
        t['reminder_at'] = f['ReminderDueBy']
    if 'ReminderIsSet' in f:
        t['reminder_on'] = f['ReminderIsSet'] == 'true'
    if 'ReminderDueBy' in f or 'ReminderIsSet' in f:
        t['reminder'] = t.get('reminder_at') if t.get('reminder_on') else None
    if 'PercentComplete' in f:
        t['percent'] = int(float(f['PercentComplete']))
    if 'Status' in f:
        t['status'] = f['Status']
        t['completed'] = now() if f['Status'] == 'Completed' else None
        if f['Status'] == 'Completed':
            t['percent'] = 100


def handle(op, el):
    if op == 'FindItem':
        parent = el.find('m:ParentFolderIds/t:DistinguishedFolderId', NS)
        if parent is None or parent.get('Id') != 'tasks':
            return None
        # as OVH Hosted Exchange: a Restriction on task:Status is refused
        if el.find('m:Restriction//t:FieldURI[@FieldURI="task:Status"]', NS) is not None:
            return wrap(op, [err(op, 'ErrorInvalidValueForProperty', 'The specified value is invalid for property.')])
        items = list(TASKS.values())
        return wrap(op, [ok(op, '<m:RootFolder TotalItemsInView="%d" IncludesLastItemInRange="true"><t:Items>%s</t:Items></m:RootFolder>'
                            % (len(items), ''.join(task_xml(t) for t in items)))])
    if op == 'CreateItem':
        task = el.find('m:Items/t:Task', NS)
        if task is None:
            return None
        if el.find('m:SavedItemFolderId/t:DistinguishedFolderId[@Id="tasks"]', NS) is None:
            return wrap(op, [err(op, 'ErrorInvalidRequest', 'tasks go to the tasks folder')])
        f = fields(task)
        tid = add(f.get('Subject', ''))
        apply(TASKS[tid], f)
        return wrap(op, [ok(op, '<m:Items><t:Task><t:ItemId Id="%s" ChangeKey="CK"/></t:Task></m:Items>' % tid)])
    if op in ('GetItem', 'UpdateItem', 'DeleteItem'):
        if op == 'UpdateItem':
            changes = el.findall('m:ItemChanges/t:ItemChange', NS)
            ids = [c.find('t:ItemId', NS).get('Id') for c in changes]
        else:
            ids = item_ids(el)
        if not ids or not all(i.startswith('TASK-') for i in ids):
            return None
        out = []
        if op == 'GetItem':
            for i in ids:
                out.append(ok(op, '<m:Items>%s</m:Items>' % task_xml(TASKS[i], True)) if i in TASKS
                           else err(op, 'ErrorItemNotFound', 'The specified object was not found in the store.'))
        elif op == 'UpdateItem':
            for c in changes:
                t = TASKS.get(c.find('t:ItemId', NS).get('Id'))
                if t is None:
                    out.append(err(op, 'ErrorItemNotFound', 'The specified object was not found in the store.'))
                    continue
                for s in c.findall('t:Updates/t:SetItemField/t:Task', NS):
                    apply(t, fields(s))
                for d in c.findall('t:Updates/t:DeleteItemField/t:FieldURI', NS):
                    if d.get('FieldURI') == 'task:DueDate':
                        t['due'] = None
                out.append(ok(op, '<m:Items><t:Task><t:ItemId Id="%s" ChangeKey="CK2"/></t:Task></m:Items><m:ConflictResults><t:Count>0</t:Count></m:ConflictResults>' % t['id']))
        else:
            if el.get('AffectedTaskOccurrences') is None:
                return wrap(op, [err(op, 'ErrorInvalidRequest', 'AffectedTaskOccurrences is required to delete a task')])
            for i in ids:
                out.append(ok(op) if TASKS.pop(i, None) else err(op, 'ErrorItemNotFound', 'The specified object was not found in the store.'))
        return wrap(op, out)
    return None
