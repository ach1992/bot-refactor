"""Synthetic support inputs and explicit scripted reads; never an expected-output recorder."""
from __future__ import annotations
import copy
import json

def read(table,rows,extra=None):
    return {'pattern':'/^SELECT '+chr(92)+'* FROM `'+table+'`/', 'rows':rows, 'extra':extra or {}}

def ticket(i=7,state=0,owner=1001):
    return {'id':i,'user_id':owner,'create_date':1800000000-i,'title':"fixture /'# title",'category':'Technical','state':state,'rate':4}

def message(i=7,photo=False,admin=False):
    return {'id':70+i,'chat_id':i,'sent_date':1800000000,'msg_type':'ADMIN' if admin else 'USER',
            'text':json.dumps({'file_id':'fixture-photo','caption':'fixture caption'}) if photo else 'fixture last message'}

def categories(n):
    return [{'id':50+i,'type':'TICKETS_CATEGORY','value':'Category '+str(i)} for i in range(n)]

def build_cases():
    cases={}
    def add(name,**v):
        assert name not in cases
        cases[name]=v
    add('entry.menu',data='supportSection')
    add('entry.reenter',data='supportSection',repetitions=2)
    for n in [0,1,2,3,6]:add('entry.categories.'+str(n),data='usersNewTicket',reads=[read('setting',categories(n))])
    add('entry.category_read_error',data='usersNewTicket',reads=[read('setting',[],{'throws':'fixture database read failure'})],error='Exception')
    add('create.choose',data='supportCatTechnical')
    add('create.choose_unicode',data='supportCat\u0641\u0646\u06cc')
    add('create.title',step='newTicket_Technical',text="new /'# title",expect_state='sendTicket_Technical')
    add('create.cancel_title',step='newTicket_Technical',text='__CANCEL__')
    add('create.overlay',data='supportCatNew',step='newTicket_Old',text='new title',expect_state='sendTicket_Old')
    add('create.text',step='sendTicket_Technical',text="body /'# \u0633\u0644\u0627\u0645",expect_state='none')
    add('create.photo',step='sendTicket_Technical',text=None,photo=True,expect_state='none')
    add('create.unsupported',step='sendTicket_Technical',text=None)
    add('create.cancel_body',step='sendTicket_Technical',text='__CANCEL__')
    add('create.insert_error',step='sendTicket_Technical',write_failure='/INSERT INTO `chats`/',error='Exception')
    add('create.message_write_error',step='sendTicket_Technical',write_failure='/INSERT INTO `chats_info`/',error='Exception')
    add('create.notification_error',step='sendTicket_Technical',throw_notification='sendToAllAdmins',error='RuntimeException')
    for route in ['usersOpenTickets','userAllTickets']:
        for n in [0,1,5,6]:
            rows=[ticket(7+i,i%3) for i in range(n)]
            reads=[read('chats',rows)]+[read('chats_info',[message(r['id'],photo=i%2==1,admin=i%3==0)]) for i,r in enumerate(rows[:5])]
            add('customer.list.'+route+'.'+str(n),data=route,reads=reads,stops=n==0,expect_state='none')
    for actor,is_admin in [(1001,0),(2002,0),(9001,0),(9002,1)]:
        count=2 if is_admin else 1
        add('close.'+str(actor),data='closeTicket_7',actor=actor,is_admin=is_admin,reads=[read('chats',[ticket()]) for _ in range(count)])
    add('close.missing',data='closeTicket_7',reads=[read('chats',[])])
    add('reply.choose',data='replySupport_7',expect_state='sendMsg_7')
    for photo in [False,True]:
        add('reply.send.'+str(photo),step='sendMsg_7',text=None if photo else "reply /'#",photo=photo,reads=[read('chats',[ticket()])],expect_state='none')
    add('reply.cancel',step='sendMsg_7',text='__CANCEL__')
    add('reply.write_error',step='sendMsg_7',reads=[read('chats',[ticket()])],write_failure='/INSERT INTO `chats_info`/',error='Exception')
    for data in ['rate_7_1','rate_7_5','rate_123_5','rate_7_9','rate__7__3','rate_bad','rate_7_50']:
        matched=data!='rate_bad'
        add('rating.'+data,data=data,reads=[read('chats',[ticket(3 if '123' in data else 7)])] if matched else [])
    for actor,is_admin in [(1001,0),(9001,0),(9002,1)]:
        allowed=actor==9001 or is_admin
        for route in ['ticketsList','ticketsCategory','addTicketCategory']:
            add('admin.route.'+route+'.'+str(actor),data=route,actor=actor,is_admin=is_admin,
                reads=[read('setting',categories(2))] if allowed and route=='ticketsCategory' else [])
        add('admin.category_input.'+str(actor),step='addTicketCategory',actor=actor,is_admin=is_admin,
            reads=[read('setting',categories(1))] if allowed else [])
        add('admin.category_delete.'+str(actor),data='delTicketCat_50',actor=actor,is_admin=is_admin,
            reads=[read('setting',categories(1))] if allowed else [])
    add('admin.category_empty',data='ticketsCategory',actor=9001,reads=[read('setting',[])])
    add('admin.category_cancel_legacy',step='addTicketCategory',actor=9001,text='__CANCEL__',reads=[read('setting',[])])
    add('admin.category_delete_empty',data='delTicketCat_50',actor=9001,reads=[read('setting',[])])
    add('admin.category_write_error',step='addTicketCategory',actor=9001,write_failure='/INSERT INTO `setting`/',error='Exception')
    for actor,is_admin in [(1001,0),(9001,0),(9002,1)]:
        for route in ['openTickets','newTickets','allTickets']:
            for n in [0,1,6]:
                allowed=actor==9001 or is_admin
                rows=[ticket(7+i,i%3) for i in range(n)]
                reads=([read('chats',rows)]+[read('chats_info',[message(r['id'],admin=i%2==1)]) for i,r in enumerate(rows[:5])]) if allowed else []
                add('queue.'+route+'.'+str(actor)+'.'+str(n),data=route,actor=actor,is_admin=is_admin,reads=reads)
    for kind in ['2','0','all']:
        for offset,n in [(0,0),(0,6),(5,17),(10,11)]:
            rows=[ticket(7+i,i%3) for i in range(n)]
            shown=rows[offset:offset+(5+offset)]
            add('queue.page.'+kind+'.'+str(offset)+'.'+str(n),data='moreTicket_'+kind+'_'+str(offset),actor=9001,
                reads=[read('chats',rows)]+[read('chats_info',[message(r['id'])]) for r in shown])
    add('queue.page_customer_denied',data='moreTicket_all_5')
    add('queue.invalid_type',data='moreTicket_unknown_5',actor=9001,error='Error')
    for n in [0,1,3]:
        rows=[message(7+i,photo=i==1,admin=i==2) for i in range(n)]
        add('history.'+str(n),data='latestMsg_7',reads=[read('chats_info',rows)])
    add('history.cross_download',data='latestMsg_7',reads=[read('chats_info',[dict(message(),text='/dlPic77')]),read('chats_info',[message(photo=True)]),read('chats',[ticket()])])
    for actor,is_admin in [(1001,0),(2002,0),(9001,0),(9002,1)]:
        add('attachment.'+str(actor),text='/dlPic77',actor=actor,is_admin=is_admin,
            reads=[read('chats_info',[message(photo=True)]),read('chats',[ticket()])])
    add('attachment.missing',text='/dlPic77',reads=[read('chats_info',[])])
    for actor,is_admin in [(1001,0),(9001,0),(9002,1)]:
        allowed=actor==9001 or is_admin
        add('admin.reply_choose.'+str(actor),data='reply_7',actor=actor,is_admin=is_admin)
        for photo in [False,True]:
            add('admin.reply_send.'+str(actor)+'.'+str(photo),step='answer_7',actor=actor,is_admin=is_admin,
                text=None if photo else "admin /'# reply",photo=photo,reads=[read('chats',[ticket()])] if allowed else [])
    add('admin.reply_cancel',step='answer_7',actor=9001,text='__CANCEL__')
    add('admin.reply_error',step='answer_7',actor=9001,reads=[read('chats',[ticket()])],write_failure='/INSERT INTO `chats_info`/',error='Exception')
    add('unrelated.noop',data='wizwizch')
    return cases
