create table cf_fields
(
    cf_fields_id int auto_increment
        primary key,
    cf_forms_id  int                     not null,
    label        varchar(200)            not null,
    field_type   varchar(20)             not null comment 'text, email, tel, textarea, select, radio, checkboxes, checkbox',
    options      text                    null comment 'one choice per line, for select, radio and checkboxes',
    help         varchar(500)            null comment 'a line of help under the field',
    is_required  tinyint(1) default 0    not null,
    sort_order   int        default 0    not null,
    deleted      tinyint(1) default 0    not null comment 'a removed field; past messages keep their own copy of it',
    key idx_form (cf_forms_id, deleted, sort_order)
)
    comment 'ContactForm fields' charset = utf8mb4 collate = utf8mb4_unicode_ci;
