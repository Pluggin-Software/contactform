<?php

$config = [
    "module_machine_name"=>"es_contactform",
    "version_number"=> '1.0.9',
    // templates render with Twig autoescape on (Templater::declares_autoescape())
    'autoescape' => true,
    "stability"=> 'beta',
    "module_name"=> "ContactForm",
    "author"=>"Kevin Bruce",
    "css"=>[
        "styles.css"
    ],
    "js"=>[
        "javascript.js"
    ],
    'db_tables'=>[
        'cf_forms',
        'cf_fields',
        'cf_submissions',
    ],
    // Admin builds forms and reads every message. Inbox reads and handles
    // messages and nothing else. Site admins (1, 2) are both. Sending a message
    // needs no role at all: the form is for the public.
    'roles'=>[
        '[ContactForm] Admin',
        '[ContactForm] Inbox',
    ],
    'welcome'=>[
        'title' => 'Contact Forms',
        'blurb' => 'Messages people send through the site\'s contact forms land in an inbox here, and are emailed to each form\'s recipients.',
        'url'   => '',
    ],
];
