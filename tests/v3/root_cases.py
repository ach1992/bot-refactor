"""Whole bot.php integration cases, with real legacy helpers and fail-closed I/O doubles."""
from cases import read,ticket,message,categories

def build_root_cases():
    user=read('users','__USER__');menu={'pattern':'/MAIN_BUTTONS/','rows':[]}
    return {
        'support-menu':{'data':'supportSection'},
        'categories-none':{'data':'usersNewTicket','reads':[read('setting',[])]},
        'categories-three':{'data':'usersNewTicket','reads':[read('setting',categories(3))]},
        'choose-category':{'data':'supportCatTechnical','reads':[user]},
        'title-to-body':{'step':'newTicket_Technical','text':"fixture /'# title",'reads':[user,user]},
        'create-text':{'step':'sendTicket_Technical','text':"fixture /'# body",'reads':[menu,user,user]},
        'create-photo':{'step':'sendTicket_Technical','text':None,'photo':True,'reads':[menu,user,user]},
        'create-unsupported':{'step':'sendTicket_Technical','text':None},
        'user-open-empty':{'data':'usersOpenTickets','reads':[read('chats',[]),user]},
        'user-all-one':{'data':'userAllTickets','reads':[read('chats',[ticket()]),user,read('chats_info',[message()])]},
        'user-close':{'data':'closeTicket_7','reads':[read('chats',[ticket()])]},
        'root-close':{'data':'closeTicket_7','actor':9001,'reads':[read('chats',[ticket()])]},
        'delegate-close':{'data':'closeTicket_7','actor':9002,'is_admin':1,'reads':[read('chats',[ticket()]),read('chats',[ticket()])]},
        'choose-reply':{'data':'replySupport_7','reads':[user]},
        'reply-text':{'step':'sendMsg_7','reads':[read('chats',[ticket()]),menu,user]},
        'reply-photo':{'step':'sendMsg_7','text':None,'photo':True,'reads':[read('chats',[ticket()]),menu,user]},
        'rating-legacy-id':{'data':'rate_123_5','reads':[read('chats',[ticket(3)])]},
        'admin-menu':{'data':'ticketsList','actor':9001},
        'admin-menu-denied':{'data':'ticketsList'},
        'admin-categories':{'data':'ticketsCategory','actor':9001,'reads':[read('setting',categories(2))]},
        'category-start':{'data':'addTicketCategory','actor':9001,'reads':[user]},
        'category-save':{'step':'addTicketCategory','actor':9001,'reads':[user,read('setting',categories(2))]},
        'category-delete':{'data':'delTicketCat_50','actor':9001,'reads':[read('setting',[])]},
        'admin-queue-empty':{'data':'openTickets','actor':9001,'reads':[read('chats',[])]},
        'page-denied':{'data':'moreTicket_all_5'},
        'history':{'data':'latestMsg_7','reads':[read('chats_info',[message()])]},
        'admin-reply-start':{'data':'reply_7','actor':9001,'reads':[user]},
        'admin-reply-text':{'step':'answer_7','actor':9001,'reads':[read('chats',[ticket()]),user]},
        'admin-reply-photo':{'step':'answer_7','actor':9001,'text':None,'photo':True,'reads':[read('chats',[ticket()]),user]},
        'cancel-title':{'step':'newTicket_Technical','text':'__CANCEL__','reads':[user,menu]},
        'cancel-reply':{'step':'sendMsg_7','text':'__CANCEL__','reads':[user,menu]},
        'cancel-admin-reply':{'step':'answer_7','actor':9001,'text':'__CANCEL__','reads':[user,menu]},
    }
