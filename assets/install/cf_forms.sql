create table cf_forms
(
    cf_forms_id    int auto_increment
        primary key,
    title          varchar(200)                         not null comment 'the heading over the form, and how the inbox names it',
    intro          text                                 null comment 'plain text shown above the fields',
    recipients     varchar(1000)                        null comment 'comma list of addresses each message is emailed to',
    subject_prefix varchar(100)                         null comment 'put in front of the email subject, for mail filters',
    thank_you      text                                 null comment 'plain text shown after a message is sent',
    submit_label   varchar(60)                          null comment 'the send button; blank is Send',
    is_active      tinyint(1) default 1                 not null comment '0 the page says the form is closed',
    created_by     int                                  null,
    date_created   timestamp  default CURRENT_TIMESTAMP null,
    date_updated   timestamp                            null on update CURRENT_TIMESTAMP,
    deleted        tinyint(1) default 0                 not null
)
    comment 'ContactForm forms' charset = utf8mb4 collate = utf8mb4_unicode_ci;
