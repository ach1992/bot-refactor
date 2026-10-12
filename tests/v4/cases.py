"""Deterministic V4 administrator/agent legacy compatibility cases."""
from __future__ import annotations
import json

def read(table, rows, extra=None):
    tick=chr(96)
    return {'pattern':r'/^SELECT \* FROM '+tick+table+tick+r'/', 'rows':rows, 'extra':extra or {}}

def person(agent=1,discount=None,step='none'):
    if discount is None:discount={'normal':'10','servers':{'3':'15'},'plans':{'7':'12'}}
    return {'id':1,'userid':1001,'name':'Fixture Agent','username':'fixture_agent',
            'discount_percent':json.dumps(discount,ensure_ascii=True),'is_agent':agent,
            'step':step,'wallet':1000,'isAdmin':0,'agent_date':1800000000}

def plan(i=7):
    return {'id':i,'title':'Fixture Plan '+str(i),'catid':5,'price':12000,'active':1}

def server(i=3):
    return {'id':i,'title':'Fixture Server '+str(i)}

def build_cases():
    cases={}
    def add(name,**v):
        assert name not in cases,name
        cases[name]=v
    actors=[(1001,0),(9001,0),(9002,1),(9002,0),('9001',0)]
    for actor,is_admin in actors:
        suffix=repr(actor)+'-'+str(is_admin)
        primary=actor==9001 and isinstance(actor,int)
        delegated=(str(actor)=='9001' or is_admin==1)
        add('roles.list.'+suffix,actor=actor,is_admin=is_admin,data='adminsList')
        add('roles.delete.'+suffix,actor=actor,is_admin=is_admin,data='delAdmin1001')
        add('roles.add.'+suffix,actor=actor,is_admin=is_admin,data='addNewAdmin')
        for value in ['1001','not-a-number','__CANCEL__','0']:
            add('roles.save.'+suffix+'-'+value,actor=actor,is_admin=is_admin,step='addNewAdmin',text=value)
        add('agents.list.'+suffix,actor=actor,is_admin=is_admin,data='agentsList')
        add('agents.empty.'+suffix,actor=actor,is_admin=is_admin,data='agentsList',keys_null=True)
        add('agents.page.'+suffix,actor=actor,is_admin=is_admin,data='nextAgentList20')
        add('agents.details.'+suffix,actor=actor,is_admin=is_admin,data='agentDetails1001')
        add('agents.remove.'+suffix,actor=actor,is_admin=is_admin,data='removeAgent1001')
        add('agents.percent.'+suffix,actor=actor,is_admin=is_admin,data='agentPercentDetails1001',
            reads=[read('users',[person()])] if delegated else [])
        add('agent.rejectlist.'+suffix,actor=actor,is_admin=is_admin,data='rejectedAgentList')
        add('agent.rejectrelease.'+suffix,actor=actor,is_admin=is_admin,data='releaseRejectedAgent1001')
        add('ban.start.'+suffix,actor=actor,is_admin=is_admin,data='banUser')
        add('unban.start.'+suffix,actor=actor,is_admin=is_admin,data='unbanUser')
        for status in ['none','banned','missing']:
            rows=[] if status=='missing' else [person(step=status)]
            add('ban.complete.'+suffix+'-'+status,actor=actor,is_admin=is_admin,
                step='banUser',text='1001',reads=[read('users',rows)] if delegated else [])
            add('unban.complete.'+suffix+'-'+status,actor=actor,is_admin=is_admin,
                step='unbanUser',text='1001',reads=[read('users',rows)] if delegated else [])
        add('ban.nonnumeric.'+suffix,actor=actor,is_admin=is_admin,step='banUser',text='not-number')
        add('unban.nonnumeric.'+suffix,actor=actor,is_admin=is_admin,step='unbanUser',text='not-number')
        add('agent.apply.decline.'+suffix,actor=actor,is_admin=is_admin,data='agencyDecline1001')
        add('agent.apply.approve.'+suffix,actor=actor,is_admin=is_admin,data='agencyApprove1001')
        add('agent.apply.input.'+suffix,actor=actor,is_admin=is_admin,step='agencyApprove1001_71',text='12')
    for status in [-1,0,1,2,3]:
        add('agent.request.'+str(status),data='requestAgency',is_agent=status)
        add('agent.menu.'+str(status),data='agencySettings',is_agent=status)
    add('agent.request.reportoff',data='requestAgency',is_agent=0)
    for actor,is_admin in [(1001,0),(9001,0),(9002,1)]:
        allow=actor==9001 or is_admin==1
        for category in ['Plan','Server']:
            for n in [0,1,3]:
                rs=[person()] if allow else []
                if allow:
                    rs.append(read('server_plans' if category=='Plan' else 'server_info',
                                   [plan(7+i) if category=='Plan' else server(3+i) for i in range(n)]))
                    if category=='Plan':
                        rs.extend(read('server_categories',[{'id':5,'title':'Fixture Cat'}]) for _ in range(n))
                reads=[read('users',[person()]),*rs[1:]] if allow else []
                add('discount.add.'+category+'.'+str(n)+'.'+str(actor),
                    data='addDiscount'+category+'Agent1001',actor=actor,is_admin=is_admin,reads=reads)
        for n in [0,1,3]:
            reads=[read('users',[person()]),read('server_plans',[plan(7+i) for i in range(n)])] if allow else []
            if allow:reads.extend(read('server_categories',[{'id':5,'title':'Fixture Cat'}]) for _ in range(n))
            add('discount.page.'+str(n)+'.'+str(actor),data='nextAgentDiscountPlan1001_20',actor=actor,is_admin=is_admin,reads=reads)
        for kind in ['Plan','Server']:
            add('discount.remove.'+kind+'.'+str(actor),data='removePercentOfAgent'+kind+'1001_3',
                actor=actor,is_admin=is_admin,reads=[read('users',[person()])] if allow else [])
        for kind in ['Plan','Server','Normal']:
            add('discount.edit.start.'+kind+'.'+str(actor),data='editAgentDiscount'+kind+'1001_3',
                actor=actor,is_admin=is_admin)
            for value in ['13','nonnumeric','__CANCEL__']:
                add('discount.edit.finish.'+kind+'.'+str(actor)+'.'+value,
                    step='editAgentDiscount'+kind+'1001_3',text=value,actor=actor,is_admin=is_admin,
                    reads=[read('users',[person()])] if allow and value=='13' else [])
    for data in ['agentsList','adminsList','requestAgency','banUser','agencySettings']:
        add('repeat.'+data,data=data,repetitions=2,is_agent=1,actor=9001)
    add('agent.invalid.discount_rows',data='agentPercentDetails1001',actor=9001,reads=[read('users',[])])
    add('agent.sql.read_error',data='agentPercentDetails1001',actor=9001,reads=[read('users',[],{'throws':'fixture read failure'})],error='Exception')
    add('agent.sql.write_error',data='removeAgent1001',actor=9001,write_failure=r'/UPDATE \x60users\x60/',error='Exception')
    add('unrelated.noop',data='wizwizch')
    return cases
