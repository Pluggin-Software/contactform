create table cf_submissions
(
    cf_submissions_id int auto_increment
        primary key,
    cf_forms_id       int                                   not null,
    core_pages_id     int                                   null comment 'the page it was sent from',
    data              mediumtext                            not null comment 'JSON list of {id,label,type,value}: the form as it was when sent',
    data_text         mediumtext                            null comment 'labels and answers as plain text, for search',
    sender_name       varchar(200)                          null comment 'the first field labelled as a name',
    sender_email      varchar(254)                          null comment 'the first email field: the Reply-To',
    status            varchar(10) default 'new'             not null comment 'new, handled or spam',
    spam_reason       varchar(255)                          null comment 'why it was taken for spam, in words staff can judge',
    mailed            tinyint(1)                            null comment '1 sent, 0 failed, NULL never tried (spam)',
    mail_error        varchar(500)                          null,
    ip_address        varchar(45)                           null,
    user_agent        varchar(512)                          null,
    user_id           int                                   null comment 'signed in when they sent it',
    handled_by        int                                   null,
    date_handled      datetime                              null,
    date_created      timestamp   default CURRENT_TIMESTAMP null,
    deleted           tinyint(1)  default 0                 not null,
    key idx_inbox (deleted, status, date_created),
    key idx_rate (ip_address, date_created)
)
    comment 'ContactForm messages received' charset = utf8mb4 collate = utf8mb4_unicode_ci;
