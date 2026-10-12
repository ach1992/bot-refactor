"""Representative complete bot.php routing inputs, compared against integrated V3."""
from __future__ import annotations

def read(table,rows):
    return {'pattern':r'/^SELECT \* FROM '+chr(96)+table+chr(96)+r'/', 'rows':rows}
def count_read():
    return {'pattern':r'/^SELECT COUNT\(/', 'rows':[{'count':0,'total':0}]}
def row():
    return {'id':1,'userid':1001,'name':'Fixture','username':'fixture_user','agent_date':1800000000,
            'discount_percent':'{"normal":"10","servers":{},"plans":{}}','is_agent':1}

def build_root_cases():
    cases={}
    def add(name,**spec):
        assert name not in cases
        cases[name]=spec
    for actor,is_admin in [(1001,0),(9001,0),(9002,1),(9002,0)]:
        ident=str(actor)+'-'+str(is_admin)
        for data in ['adminsList','addNewAdmin','agentsList','agentDetails1001',
                     'agencySettings','requestAgency','agencyDecline1001',
                     'agencyApprove1001','rejectedAgentList','banUser','unbanUser']:
            allowed=actor==9001 or is_admin==1
            reads=[]
            if data=='adminsList' and actor==9001:reads=[read('users',[])]
            elif data=='agentsList' and allowed:reads=[read('users',[])]
            elif data=='agentDetails1001' and allowed:
                reads=[read('users',[row()])]+[count_read() for _ in range(4)]
            elif data=='rejectedAgentList' and allowed:reads=[read('users',[])]
            elif (data=='addNewAdmin' and actor==9001) or (data in ('agencyDecline1001','agencyApprove1001','banUser','unbanUser') and allowed):
                reads=[read('users',['__USER__'])]
            add('router.'+data+'.'+ident,data=data,actor=actor,is_admin=is_admin,
                is_agent=2 if data=='requestAgency' else 0,reads=reads,get_chat=(data=='agentDetails1001' and allowed))
        for step,text in [('addNewAdmin','foo'),('banUser','foo'),('unbanUser','foo'),
                          ('agencyApprove1001_71','bad-percent')]:
            add('wizard.'+step+'.'+ident,actor=actor,is_admin=is_admin,step=step,text=text)
    for status in [-1,0,1,2]:
        add('request.'+str(status),data='requestAgency',is_agent=status,reads=[read('users',['__USER__'])] if status==0 else ([read('setting',[])] if status==1 else []))
    add('unrelated.legacy_callback',data='wizwizch')
    return cases
