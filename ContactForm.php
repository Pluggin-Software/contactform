<?php
declare(strict_types=0);

namespace estuaries_modules\ContactForm;

use estuaries\Authentication;
use estuaries\Core;
use estuaries\ListView;
use estuaries\MailFailure;
use estuaries\Mailer;
use estuaries\Modules;
use estuaries\ModulesInterface;
use estuaries\Search;
use estuaries\Templater;

/**
 * Contact forms (1.0.0, hardened in 1.0.1): forms an admin builds, a page that shows one, and an
 * inbox for what people send.
 *
 * A page attached to this module picks its form in the page editor (the
 * module's optional_views_for_page, a single choice of form). A page that picks
 * none shows the only active form, if there is exactly one.
 *
 * Every message is STORED, then emailed to the form's recipients, so a mail
 * server that refuses it loses nothing: the inbox shows the failure and offers
 * to send it again. Each message keeps its own copy of the fields as they were
 * when it was sent, so editing or removing a field never rewrites history.
 *
 * Spam is kept out without asking anything of the visitor:
 *  - a cap on messages per address per window: over it, the sender is told;
 *  - a honeypot field a person never sees, a minimum time between showing the
 *    form and sending it, and a cap on links: a message that trips any of them
 *    is KEPT, in the Spam tab with its reason, and not emailed (1.0.1). It is
 *    answered exactly as a sent one, so a bot learns nothing, and a person a
 *    check misjudged (an autofilled honeypot, a fast resend) can be rescued.
 * Nothing a visitor sends is ever silently thrown away.
 */
class ContactForm extends Modules implements ModulesInterface
{
    /*
     * The methods a request may name. submit_message() is the one public write
     * (it is the contact form) and gates itself on the form being open and the
     * spam checks; every other handler asks for the module's roles first.
     * Pinned by tests/Unit/ContactForm/ContactFormSurfaceTest.php.
     */
    public const REQUEST_METHODS = [
        'submit_message',
        'ajax_inbox_list',
        'ajax_forms_list',
        'set_status',
        'resend',
        'save_form',
        'delete_form',
    ];

    public const ROLE_ADMIN = '[ContactForm] Admin';
    public const ROLE_INBOX = '[ContactForm] Inbox';

    /** The kinds of field a form can hold, with what the builder calls them. */
    public const FIELD_TYPES = [
        'text'       => 'Short text',
        'email'      => 'Email address',
        'tel'        => 'Phone number',
        'textarea'   => 'Long text',
        'select'     => 'Drop-down list',
        'radio'      => 'Choose one',
        'checkboxes' => 'Choose any',
        'checkbox'   => 'Tick box',
    ];

    /** The field types that need a list of choices. */
    public const CHOICE_TYPES = ['select', 'radio', 'checkboxes'];

    public const STATUSES = ['new' => 'New', 'handled' => 'Handled', 'spam' => 'Spam'];

    /**
     * The hidden field only a bot fills in. Deliberately meaningless: it was
     * cf_website until 1.0.1, and browsers and password managers autofill a
     * field that looks like a web address, which would put a real person's
     * message in Spam.
     */
    public const HONEYPOT = 'cf_extra';

    /** Seconds a person needs, at least, between the form appearing and sending it. */
    public const MIN_SECONDS = 3;

    /** At most RATE_MAX messages from one address in RATE_MINUTES. */
    public const RATE_MAX = 5;
    public const RATE_MINUTES = 10;

    /** More links than this across a message and it is kept as spam, not emailed. */
    public const MAX_LINKS = 3;

    /** The longest answer a field takes. */
    private const MAX_SHORT = 500;
    private const MAX_LONG  = 10000;

    /** Where a refused or sent message waits for the page render that follows. */
    private const STASH = 'cf_stash';

    /**
     * When each form was FIRST shown to this session: the start of the clock.
     * Kept across a refused send (1.0.1), so fixing one field and sending again
     * within MIN_SECONDS is not mistaken for a bot.
     */
    private const RENDERED = 'cf_rendered';

    /** When this session last sent each form: tells a double-click from a stale session. */
    private const SENT_AT = 'cf_sent_at';

    /** A second post this soon after a sent one is the same message, posted twice. */
    public const DUPLICATE_SECONDS = 120;

    private const INBOX_TARGET = 'cf_inbox_list';
    private const FORMS_TARGET = 'cf_forms_list';

    /** The inbox's sortable columns; the request names a key, never a column. */
    private const INBOX_SORTS = [
        'received' => 's.date_created',
        'from'     => 's.sender_name',
        'form'     => 'f.title',
    ];

    public bool $is_admin = false;
    public bool $is_inbox = false;

    /**
     * Who sends the notice: callable (array $to, string $subject, string $html,
     * ?string $reply_to): true|string, true when it went and the reason when it
     * did not. Null is Mailer. A test puts a recorder here; nothing else should.
     */
    public $mail_sender = null;

    /**
     * The time, as a callable returning a unix timestamp. Null is time(). It
     * drives the session clocks (MIN_SECONDS, DUPLICATE_SECONDS); the rate
     * window and date_handled are the database's, measured against the
     * date_created it stamps.
     */
    public $clock = null;

    /**
     * Where a handler's redirect goes: callable (string $url): void. Null sends
     * the Location header and ends the request. A test puts a recorder here.
     */
    public $redirector = null;

    /** The application_id this module's tasks carry. */
    public const APPLICATION_ID = 'contactform';

    public $url_array = [];
    public $base_url = '';

    public array $optional_views_for_page = [];

    function __construct(Core $core, $get = [], $post = [], $session = [], $module_name = __CLASS__, $attachment_id = null, $alternate_url_up_to_module = null, $module_page_vars = null)
    {
        parent::__construct($core, $get, $post, $session, $module_name, $attachment_id, $alternate_url_up_to_module, $module_page_vars);

        $this->module_links(__CLASS__);
        $this->base_url  = $this->url_up_to_module;
        $this->url_array = $this->core->module_url_array ?? [];

        $this->set_roles($session['user_roles'] ?? $this->core->session['user_roles'] ?? []);

        //what the page editor offers for a page this module is attached to
        $this->optional_views_for_page = [
            'label'    => 'Which form this page shows',
            'options'  => $this->form_choices(),
            'formtype' => 'select',
        ];

        $this->loader();
    }

    public function set_roles(?array $user_roles = []): void
    {
        $user_roles = $user_roles ?? [];
        $roles      = $this->module_config['roles'] ?? [];

        $admin = array_filter([1, 2, $roles[self::ROLE_ADMIN] ?? null]);
        $inbox = array_filter([$roles[self::ROLE_INBOX] ?? null]);

        $this->is_admin = Authentication::is_allowed($user_roles, $admin);
        $this->is_inbox = $this->is_admin || ($inbox && Authentication::is_allowed($user_roles, $inbox));
    }

    /** May read and handle messages. */
    public function may_read(): bool
    {
        return $this->is_admin || $this->is_inbox;
    }

    /** May build and change forms. */
    public function may_manage(): bool
    {
        return $this->is_admin;
    }

    public function process($get = [], $post = [])
    {
        return parent::view($get['id'] ?? $post['id'] ?? null, $get, $post);
    }

    public function add($get = [], $post = []) {}

    public function edit($id, $get = [], $post = []) {}

    public function delete($id, $get = [], $post = []) {}

    public function form_prep(array $vars_array) { return $vars_array; }

    private function now(): int
    {
        return $this->clock ? (int) ($this->clock)() : time();
    }

    private static function e($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES);
    }

    private function template(string $name, array $values): string
    {
        return Templater::fill_template($this->module_path . '/assets/templates/' . $name, null, $values);
    }

    /* =========================================================================
     * FORMS AND THEIR FIELDS
     * ---------------------------------------------------------------------- */

    /** Every form not deleted, id => title, for the page editor's choice. */
    public function form_choices(): array
    {
        $choices = [];
        foreach ($this->db->select("SELECT cf_forms_id, title, is_active FROM cf_forms WHERE deleted = 0 ORDER BY title", []) ?: [] as $row) {
            $choices[(string) $row['cf_forms_id']] = $row['title'] . ((int) $row['is_active'] ? '' : ' (closed)');
        }

        return $choices;
    }

    public function get_form(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->db->select("SELECT * FROM cf_forms WHERE cf_forms_id = :id AND deleted = 0", ['id' => $id], 1);

        return $row ?: null;
    }

    /** A form's live fields, in order, each with its choices as a list. */
    public function get_fields(int $form_id): array
    {
        $rows = $this->db->select(
            "SELECT * FROM cf_fields WHERE cf_forms_id = :id AND deleted = 0 ORDER BY sort_order, cf_fields_id",
            ['id' => $form_id]
        ) ?: [];

        foreach ($rows as $k => $row) {
            $rows[$k]['choices'] = self::choices((string) ($row['options'] ?? ''));
        }

        return $rows;
    }

    /** One choice per line, trimmed, blanks and repeats dropped. */
    public static function choices(string $options): array
    {
        $out = [];
        foreach (preg_split('/\R/', $options) as $line) {
            $line = trim($line);
            if ($line !== '' && !in_array($line, $out, true)) {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * The form this page shows: the one picked in the page editor, or else the
     * only active form, if there is exactly one.
     */
    public function page_form_id(): ?int
    {
        $vars = is_array($this->module_page_vars) ? $this->module_page_vars : [];
        $pick = $vars['views'][0] ?? $vars[0] ?? null;

        if ($pick !== null && (int) $pick > 0) {
            return (int) $pick;
        }

        $active = $this->db->select("SELECT cf_forms_id FROM cf_forms WHERE deleted = 0 AND is_active = 1 LIMIT 2", []) ?: [];

        return count($active) === 1 ? (int) $active[0]['cf_forms_id'] : null;
    }

    /* =========================================================================
     * THE PUBLIC FORM
     * ---------------------------------------------------------------------- */

    public function rout_index($get = [], $post = [])
    {
        $form_id = $this->page_form_id();
        $form    = $form_id ? $this->get_form($form_id) : null;
        $toolbar = $this->staff_toolbar();

        if (!$form) {
            if (!$this->may_manage()) {
                return $toolbar;
            }

            return $toolbar . "<div class='alert alert-info'>No contact form is chosen for this page. "
                . "<a href=\"" . self::e($this->base_url . '/forms') . "\">Build one</a>, then pick it in this page's settings.</div>";
        }

        $stash = $this->take_stash('message:' . $form['cf_forms_id']);

        if (!empty($stash['sent'])) {
            return $toolbar . $this->template('sent.twig', [
                'form'     => $form,
                'reply_to' => (string) ($stash['reply_to'] ?? ''),
                'again'    => $this->request_path(),
            ]);
        }

        if (!(int) $form['is_active']) {
            return $toolbar . "<div class='cf-public'><p class='cf-closed'>This form is not taking messages at the moment.</p></div>";
        }

        //the clock starts the first time this session sees the form, and is not
        //restarted by a refused send: fixing one field and sending again at once
        //is a person, not a bot
        $_SESSION[self::RENDERED][(int) $form['cf_forms_id']] ??= $this->now();

        $values = (array) ($stash['values'] ?? []);
        $errors = (array) ($stash['errors'] ?? []);
        $fields = [];

        $error_list = [];

        foreach ($this->get_fields((int) $form['cf_forms_id']) as $field) {
            $id = (int) $field['cf_fields_id'];
            $fields[] = $field + [
                'input_id' => 'cf_f_' . $id,
                'name'     => 'f[' . $id . ']',
                'value'    => $values[$id] ?? ($field['field_type'] === 'checkboxes' ? [] : ''),
                'error'    => $errors[$id] ?? '',
            ];
            if (isset($errors[$id])) {
                $error_list[] = ['target' => 'cf_f_' . $id, 'label' => $field['label'], 'message' => $errors[$id]];
            }
        }

        return $toolbar . $this->template('form.twig', [
            'form'     => $form,
            'fields'   => $fields,
            'errors'   => $errors,
            'error_list' => $error_list,
            'page_id'  => (int) ($this->module_page_vars['core_pages_id'] ?? 0),
            'useclass' => __CLASS__,
            'honeypot' => self::HONEYPOT,
            'action'   => $this->request_path(),
        ]);
    }

    /** The links staff see above the form: the inbox, with what is new. */
    private function staff_toolbar(): string
    {
        if (!$this->may_read()) {
            return '';
        }

        $new = (int) ($this->db->select("SELECT COUNT(*) AS n FROM cf_submissions WHERE deleted = 0 AND status = 'new'", [], 1)['n'] ?? 0);

        return $this->template('toolbar.twig', [
            'inbox'  => $this->base_url . '/inbox',
            'forms'  => $this->may_manage() ? $this->base_url . '/forms' : '',
            'new'    => $new,
        ]);
    }

    /**
     * Send the browser to $url and END the request.
     *
     * Returning after the header is not enough: the Router goes on to render
     * the page in this same POST response, and that render takes what was
     * left for the page the browser is sent to — the "sent" stash and the
     * success message — so the redirected page showed neither (1.0.4).
     */
    private function go(string $url): bool
    {
        if ($this->redirector) {
            ($this->redirector)($url);

            return true;
        }

        header('Location: ' . $url);
        exit;
    }

    /** The page the request came to, without its query: where a form posts back. */
    private function request_path(): string
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        //a site path, never another host
        return ($path === '' || $path[0] !== '/' || str_starts_with($path, '//')) ? '/' : $path;
    }

    /**
     * Take a message. The form posts to its own page, so a refused one renders
     * again with what was typed; a sent one redirects, so a reload cannot send
     * it twice.
     */
    public function submit_message($get, $post)
    {
        $form = $this->get_form((int) ($post['cf_form'] ?? 0));

        if (!$form || !(int) $form['is_active']) {
            $this->core->set_message_error('That form is not taking messages at the moment.');
            return false;
        }

        $form_id = (int) $form['cf_forms_id'];
        $key     = 'message:' . $form_id;

        $ip = substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);

        //first, so a flood is bounded whatever else it trips: everything kept
        //counts, spam included
        if ($ip !== '' && $this->recent_from($ip) >= self::RATE_MAX) {
            $this->put_stash($key, ['values' => $this->posted_values($post), 'errors' => [
                'form' => 'Several messages have just been sent from your connection. Wait a few minutes and send it again.',
            ]]);
            return false;
        }

        $shown = $_SESSION[self::RENDERED][$form_id] ?? null;

        if ($shown === null) {
            //the same message posted twice (a double-click, or a reload that
            //resent it): the first one went, so this one is answered the same
            $sent_at = $_SESSION[self::SENT_AT][$form_id] ?? null;
            if ($sent_at !== null && $this->now() - (int) $sent_at <= self::DUPLICATE_SECONDS) {
                return $this->finish_sent($key, $form_id);
            }

            //no record of showing it: a session that ran out, or a post from
            //nowhere. A person can simply send it again; what they typed is kept.
            $this->put_stash($key, ['values' => $this->posted_values($post), 'errors' => [
                'form' => 'Your session ran out before the message was sent. Nothing was lost: check it and press send again.',
            ]]);
            return false;
        }

        [$data, $values, $errors] = $this->validate($this->get_fields($form_id), (array) ($post['f'] ?? []));

        if ($errors) {
            $this->put_stash($key, ['values' => $values, 'errors' => $errors]);
            return false;
        }

        $sender  = self::sender($data);
        $reasons = $this->spam_reasons($post, $data, $this->now() - (int) $shown);
        $spam    = (bool) $reasons;

        $id = $this->db->insert(
            "INSERT INTO cf_submissions (cf_forms_id, core_pages_id, data, data_text, sender_name, sender_email, status, spam_reason, ip_address, user_agent, user_id)
             VALUES (:form, :page, :data, :text, :name, :email, :status, :reason, :ip, :ua, :user)",
            [
                'form'   => $form_id,
                'page'   => (int) ($post['cf_page'] ?? 0) ?: null,
                'data'   => json_encode($data, JSON_UNESCAPED_UNICODE),
                'text'   => self::data_text($data),
                'name'   => $sender['name'],
                'email'  => $sender['email'],
                'status' => $spam ? 'spam' : 'new',
                'reason' => $spam ? mb_substr(implode('; ', $reasons), 0, 255) : null,
                'ip'     => $ip ?: null,
                'ua'     => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512) ?: null,
                'user'   => (int) ($this->core->session['user']['user_id'] ?? 0) ?: null,
            ],
            true
        );

        if (!$id) {
            $this->put_stash($key, ['values' => $values, 'errors' => [
                'form' => 'Your message could not be saved. Nothing was sent; please try again.',
            ]]);
            return false;
        }

        if (!$spam) {
            $this->notify($form, $data, (int) $id, $sender['email']);
        }

        return $this->finish_sent($key, $form_id, $sender['email']);
    }

    /**
     * Why a message looks automated, in words a member of staff can judge, or
     * nothing when it looks like a person. Any reason sends it to Spam.
     */
    public function spam_reasons(array $post, array $data, int $seconds): array
    {
        $reasons = [];

        if (trim((string) ($post[self::HONEYPOT] ?? '')) !== '') {
            $reasons[] = 'It filled in a hidden field that only automated senders see';
        }
        if ($seconds < self::MIN_SECONDS) {
            $reasons[] = 'It was sent ' . max(0, $seconds) . ' second' . ($seconds === 1 ? '' : 's') . ' after the form appeared';
        }
        $links = self::count_links($data);
        if ($links > self::MAX_LINKS) {
            $reasons[] = 'It holds ' . $links . ' links';
        }

        return $reasons;
    }

    /** Thank them, and send the browser back to the page so a reload does not post again. */
    private function finish_sent(string $key, int $form_id, ?string $reply_to = null): bool
    {
        unset($_SESSION[self::RENDERED][$form_id]);
        $_SESSION[self::SENT_AT][$form_id] = $this->now();
        //the address replies will go to, so the thanks can say so; a plain
        //address only, as the Reply-To header takes
        $this->put_stash($key, ['sent' => true] + ($reply_to !== null && self::plain_email($reply_to) ? ['reply_to' => $reply_to] : []));
        return $this->go($this->request_path());
    }

    /** Messages from this address inside the rate window, spam included. */
    private function recent_from(string $ip): int
    {
        //the window is measured on the database's clock, which stamped
        //date_created: PHP and MySQL need not share a timezone (production
        //databases often run UTC while PHP runs America/New_York)
        return (int) ($this->db->select(
            "SELECT COUNT(*) AS n FROM cf_submissions WHERE ip_address = :ip AND date_created >= NOW() - INTERVAL :mins MINUTE",
            ['ip' => $ip, 'mins' => self::RATE_MINUTES], 1
        )['n'] ?? 0);
    }

    /** What was typed, by field id, for showing the form again. */
    private function posted_values(array $post): array
    {
        $out = [];
        foreach ((array) ($post['f'] ?? []) as $id => $value) {
            $out[(int) $id] = is_array($value) ? array_map('strval', $value) : (string) $value;
        }

        return $out;
    }

    /**
     * Check each field's answer against the field.
     *
     * @return array{0:array,1:array,2:array} the message as it will be stored
     *         (a list of {id,label,type,value}), what was typed by field id,
     *         and the errors by field id
     */
    public function validate(array $fields, array $posted): array
    {
        $data = $values = $errors = [];

        foreach ($fields as $field) {
            $id       = (int) $field['cf_fields_id'];
            $type     = (string) $field['field_type'];
            $label    = (string) $field['label'];
            $required = (int) $field['is_required'] > 0;
            $choices  = $field['choices'] ?? self::choices((string) ($field['options'] ?? ''));
            $raw      = $posted[$id] ?? null;

            if ($type === 'checkboxes') {
                $picked = array_values(array_filter(array_map(static fn($v) => trim((string) $v), (array) ($raw ?? [])), static fn($v) => $v !== ''));
                $values[$id] = $picked;

                if (array_diff($picked, $choices)) {
                    $errors[$id] = 'Choose from the list.';
                } elseif ($required && !$picked) {
                    $errors[$id] = 'Choose at least one.';
                }

                $data[] = ['id' => $id, 'label' => $label, 'type' => $type, 'value' => implode(', ', $picked)];
                continue;
            }

            $value = is_array($raw) ? '' : trim(str_replace("\r\n", "\n", (string) ($raw ?? '')));
            $values[$id] = $value;

            if ($type === 'checkbox') {
                $ticked = $value !== '';
                if ($required && !$ticked) {
                    $errors[$id] = 'This needs to be ticked.';
                }
                $data[] = ['id' => $id, 'label' => $label, 'type' => $type, 'value' => $ticked ? 'Yes' : 'No'];
                continue;
            }

            $max = $type === 'textarea' ? self::MAX_LONG : self::MAX_SHORT;

            if ($value === '') {
                if ($required) {
                    $errors[$id] = 'This is needed.';
                }
            } elseif (mb_strlen($value) > $max) {
                $errors[$id] = 'Keep this under ' . number_format($max) . ' characters.';
            } elseif ($type === 'email' && !self::plain_email($value)) {
                $errors[$id] = 'That does not look like an email address.';
            } elseif ($type === 'tel' && !preg_match('/^[0-9+().\-\s\/x#ext]{5,40}$/i', $value)) {
                $errors[$id] = 'Use digits, spaces and + ( ) - only.';
            } elseif (in_array($type, ['select', 'radio'], true) && !in_array($value, $choices, true)) {
                $errors[$id] = 'Choose from the list.';
            }

            if ($type !== 'textarea') {
                //one line: a newline in a header-bound value has no business there
                $value = preg_replace('/\s+/', ' ', $value);
            }

            $data[] = ['id' => $id, 'label' => $label, 'type' => $type, 'value' => $value];
        }

        return [$data, $values, $errors];
    }

    /**
     * An everyday address: valid, and none of the characters only a quoted
     * local part allows. FILTER_VALIDATE_EMAIL accepts "<img onerror=…>"@x.com,
     * which no person types and which carries markup wherever it is printed.
     */
    public static function plain_email(string $address): bool
    {
        return (bool) filter_var($address, FILTER_VALIDATE_EMAIL) && !preg_match('/["<>()\[\]\\\\,;:\s]/', $address);
    }

    /** Who sent it: the first email field, and the first short-text field labelled as a name. */
    public static function sender(array $data): array
    {
        $name = $email = null;

        foreach ($data as $item) {
            if ($email === null && $item['type'] === 'email' && self::plain_email($item['value'])) {
                $email = $item['value'];
            }
            if ($name === null && $item['type'] === 'text' && $item['value'] !== '' && stripos($item['label'], 'name') !== false) {
                $name = mb_substr($item['value'], 0, 200);
            }
        }

        return ['name' => $name, 'email' => $email];
    }

    /** Links across every answer: http(s)://, www., and bare [url=] tags. */
    public static function count_links(array $data): int
    {
        $n = 0;
        foreach ($data as $item) {
            $n += preg_match_all('#(https?://|www\.|\[url)#i', (string) $item['value']);
        }

        return $n;
    }

    /** The message as plain text, label then answer, for the inbox search. */
    public static function data_text(array $data): string
    {
        $lines = [];
        foreach ($data as $item) {
            $lines[] = $item['label'] . ': ' . $item['value'];
        }

        return implode("\n", $lines);
    }

    /* =========================================================================
     * THE EMAIL
     * ---------------------------------------------------------------------- */

    /** Email the form's recipients and record whether it went. */
    private function notify(array $form, array $data, int $id, ?string $reply_to): bool
    {
        //re-checked at send time, not only on save: a row stored before plain
        //addresses were required (1.0.1) may still hold a quoted one
        $to       = array_values(array_filter(Mailer::addresses((string) ($form['recipients'] ?? '')), [self::class, 'plain_email']));
        $reply_to = $reply_to !== null && self::plain_email($reply_to) ? $reply_to : null;
        $subject  = trim(trim((string) ($form['subject_prefix'] ?? '')) . ' New message: ' . $form['title']);
        $link     = rtrim((string) ($this->core->full_base_web_url ?? ''), '/') . $this->base_url . '/message/' . $id;
        //a failure's task opens the message, which is safe in the inbox
        $failed   = ['link' => $link, 'link_text' => 'Open the message',
                     'note' => 'Check the form\'s recipients too. Then open the message and press Resend.'];

        if (!$to) {
            $result = 'No recipients are set for this form.';
        } else {
            $html    = $this->template('email.twig', [
                'form'  => $form,
                'data'  => $data,
                'link'  => $link,
                'reply' => $reply_to,
            ]);
            $result = MailFailure::while_sending($failed, fn() => $this->send_mail($to, $subject, $html, $reply_to));
        }

        $this->db->update(
            "UPDATE cf_submissions SET mailed = :m, mail_error = :e WHERE cf_submissions_id = :id",
            ['m' => $result === true ? 1 : 0, 'e' => $result === true ? null : mb_substr((string) $result, 0, 500), 'id' => $id]
        );

        //the visitor is thanked whatever happens, because the message is safe in
        //the inbox, so a notice that did not go is told to the Super Users. A
        //refusal by the mail server was already reported by Mailer, and this
        //finds that task open and adds nothing; this one covers the reasons
        //that never reach it (no recipients, mail not set up).
        if ($result !== true) {
            MailFailure::report($this->core, $to, $subject, (string) $result, $failed);
        }

        return $result === true;
    }

    /** @return true|string */
    private function send_mail(array $to, string $subject, string $html, ?string $reply_to)
    {
        if ($this->mail_sender) {
            return ($this->mail_sender)($to, $subject, $html, $reply_to);
        }

        if (!defined('FROM_EMAIL')) {
            return 'Mail is not set up on this server.';
        }

        return Mailer::sendamail2($to, $subject, $html, null, null, null, $reply_to)
            ? true
            : (Mailer::$last_error ?: 'The mail server did not accept it.');
    }

    /* =========================================================================
     * THE INBOX
     * ---------------------------------------------------------------------- */

    private function inbox_list(): ListView
    {
        return new ListView(__CLASS__, 'ajax_inbox_list', self::INBOX_TARGET,
            ['received' => 'Received', 'from' => 'From', 'form' => 'Form'],
            'received', 'desc', 25, 'messages');
    }

    private function status_filter(array $src): string
    {
        $status = (string) ($src['status'] ?? 'new');

        return isset(self::STATUSES[$status]) || $status === 'all' ? $status : 'new';
    }

    public function rout_inbox($get = [], $post = [])
    {
        if (!$this->may_read()) {
            return $this->login_prompt('You do not have permission to read contact form messages.');
        }

        $src    = $this->get;
        $status = $this->status_filter($src);
        $form   = (int) ($src['form'] ?? 0);
        $list   = $this->inbox_list();

        $counts = [];
        foreach ($this->db->select("SELECT status, COUNT(*) AS n FROM cf_submissions WHERE deleted = 0 GROUP BY status", []) ?: [] as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }

        $pills = [];
        foreach (self::STATUSES + ['all' => 'All'] as $key => $label) {
            $pills[] = [
                'label'   => $label,
                'count'   => $key === 'all' ? array_sum($counts) : ($counts[$key] ?? 0),
                'href'    => $this->base_url . '/inbox?status=' . $key . ($form ? '&form=' . $form : ''),
                'current' => $key === $status,
            ];
        }

        $forms = [];
        foreach ($this->db->select("SELECT cf_forms_id, title FROM cf_forms WHERE deleted = 0 ORDER BY title", []) ?: [] as $row) {
            $forms[] = ['id' => (int) $row['cf_forms_id'], 'title' => $row['title'], 'current' => (int) $row['cf_forms_id'] === $form];
        }

        $content = $this->template('inbox.twig', [
            'pills'       => $pills,
            'forms'       => count($forms) > 1 ? $forms : [],
            'status'      => $status,
            'base'        => $this->base_url . '/inbox',
            'search_box'  => $list->search_box(ListView::query($src), 'Search messages', ['status' => $status, 'form' => $form], 'Search messages'),
            'list_target' => $list->target(),
            'list_html'   => $this->inbox_table($src),
        ]);

        return $this->page_wrapper($content, $this->head('inbox'));
    }

    /** Redraw the inbox. Reached by the search box, the sort headers and the pager. */
    public function ajax_inbox_list($get, $post): string
    {
        if (!$this->may_read()) {
            return $this->login_prompt('You do not have permission to read contact form messages.');
        }

        return $this->inbox_table($post);
    }

    private function inbox_table(array $src): string
    {
        $list   = $this->inbox_list();
        $status = $this->status_filter($src);
        $form   = (int) ($src['form'] ?? 0);
        $where  = ['s.deleted = 0'];
        $params = [];

        if ($status !== 'all') {
            $where[] = 's.status = :status';
            $params['status'] = $status;
        }
        if ($form > 0) {
            $where[] = 's.cf_forms_id = :form';
            $params['form'] = $form;
        }

        [$clause, $bind] = Search::where(ListView::query($src), ['s.data_text', 's.sender_name', 's.sender_email']);
        if ($clause !== '') {
            $where[] = $clause;
            $params += $bind;
        }

        $from  = 'FROM cf_submissions s LEFT JOIN cf_forms f ON f.cf_forms_id = s.cf_forms_id WHERE ' . implode(' AND ', $where);
        $total = (int) ($this->db->select("SELECT COUNT(*) AS c {$from}", $params, 1)['c'] ?? 0);
        $state = $list->state($src, $total);
        $dir   = $state['dir'] === 'desc' ? 'DESC' : 'ASC';
        $sort  = self::INBOX_SORTS[$state['sort']] ?? self::INBOX_SORTS['received'];

        $rows = $total === 0 ? [] : ($this->db->select(
            "SELECT s.cf_submissions_id, s.data, s.sender_name, s.sender_email, s.status, s.spam_reason, s.mailed, s.date_created, f.title AS form_title
               {$from}
              ORDER BY {$sort} {$dir}, s.cf_submissions_id {$dir}
              LIMIT {$state['limit']} OFFSET {$state['offset']}",
            $params
        ) ?: []);

        foreach ($rows as $k => $row) {
            $rows[$k]['preview'] = self::preview((array) json_decode((string) $row['data'], true));
            $rows[$k]['href']    = $this->base_url . '/message/' . (int) $row['cf_submissions_id'];
            $rows[$k]['when']    = $row['date_created'] ? date('M j, Y g:ia', strtotime((string) $row['date_created'])) : '';
            $rows[$k]['mail_failed'] = $row['mailed'] !== null && (int) $row['mailed'] === 0;
        }

        return $this->template('inbox-table.twig', [
            'rows'     => $rows,
            'status'   => $status,
            'searched' => Search::has_terms(ListView::query($src)),
            'list'     => $list->controls($state, ['status' => $status, 'form' => $form]),
        ]);
    }

    /** A line to recognise a message by: its first long answer, or its answers run together. */
    public static function preview(array $data, int $length = 140): string
    {
        $text = '';
        foreach ($data as $item) {
            if (($item['type'] ?? '') === 'textarea' && trim((string) $item['value']) !== '') {
                $text = (string) $item['value'];
                break;
            }
        }
        if ($text === '') {
            $text = implode(' · ', array_filter(array_map(static fn($i) => (string) ($i['value'] ?? ''), $data), static fn($v) => $v !== '' && $v !== 'No'));
        }

        $text = preg_replace('/\s+/', ' ', trim($text));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length - 1) . '…' : $text;
    }

    public function rout_message($get = [], $post = [])
    {
        if (!$this->may_read()) {
            return $this->login_prompt('You do not have permission to read contact form messages.');
        }

        $id  = (int) ($get['id'] ?? 0);
        $row = $this->get_submission($id);

        if (!$row) {
            return $this->page_wrapper("<div class='cf-card'><p>That message could not be found. It may have been deleted.</p>"
                . "<p><a href=\"" . self::e($this->base_url . '/inbox') . "\">Back to the inbox</a></p></div>",
                ['title' => 'Not found', 'breadcrumb' => [['label' => 'Inbox', 'href' => $this->base_url . '/inbox'], ['label' => 'Not found']], 'compact' => true]);
        }

        $handler = null;
        if ($row['handled_by']) {
            $u = $this->db->select("SELECT user_first, user_last FROM users WHERE user_id = :id", ['id' => (int) $row['handled_by']], 1) ?: [];
            $handler = trim(($u['user_first'] ?? '') . ' ' . ($u['user_last'] ?? '')) ?: null;
        }

        $content = $this->template('message.twig', [
            'm'        => $row,
            'data'     => (array) json_decode((string) $row['data'], true),
            'when'     => $row['date_created'] ? date('l, F j, Y \a\t g:ia', strtotime((string) $row['date_created'])) : '',
            'handled'  => $row['date_handled'] ? date('M j, Y', strtotime((string) $row['date_handled'])) : '',
            'handler'  => $handler,
            'useclass' => __CLASS__,
            'action'   => $this->base_url . '/message/' . $id,
            'statuses' => self::STATUSES,
            'mail_failed' => $row['mailed'] !== null && (int) $row['mailed'] === 0,
        ]);

        return $this->page_wrapper($content, [
            'title'      => $row['sender_name'] ?: ($row['sender_email'] ?: 'Message #' . $id),
            'subtitle'   => ($row['form_title'] ?? 'A deleted form') . ' · ' . (self::STATUSES[$row['status']] ?? $row['status']),
            'breadcrumb' => [['label' => 'Inbox', 'href' => $this->base_url . '/inbox'], ['label' => 'Message #' . $id]],
            'compact'    => true,
        ]);
    }

    public function get_submission(int $id): ?array
    {
        if ($id < 1) {
            return null;
        }

        $row = $this->db->select(
            "SELECT s.*, f.title AS form_title, f.recipients
               FROM cf_submissions s LEFT JOIN cf_forms f ON f.cf_forms_id = s.cf_forms_id
              WHERE s.cf_submissions_id = :id AND s.deleted = 0",
            ['id' => $id], 1
        );

        return $row ?: null;
    }

    /** Mark a message new, handled or spam, or delete it. */
    public function set_status($get, $post)
    {
        if (!$this->may_read()) {
            $this->core->set_message_error('You do not have permission to change contact form messages.');
            return false;
        }

        $id     = (int) ($post['cf_submissions_id'] ?? 0);
        $status = (string) ($post['status'] ?? '');
        $row    = $this->get_submission($id);

        if (!$row || !(isset(self::STATUSES[$status]) || $status === 'deleted')) {
            $this->core->set_message_error('That message could not be found. It may have been deleted.');
            return false;
        }

        if ($status === 'deleted') {
            $this->db->update("UPDATE cf_submissions SET deleted = 1 WHERE cf_submissions_id = :id", ['id' => $id]);
            $this->core->set_message_success('The message was deleted.');
            $this->go($this->base_url . '/inbox');
            return true;
        }

        $handled = $status === 'handled';
        $this->db->update(
            //stamped by the database, like date_created, so the two can never
            //disagree about which came first (PHP and MySQL need not share a
            //timezone)
            "UPDATE cf_submissions SET status = :status, handled_by = :by, date_handled = IF(:handled, NOW(), NULL) WHERE cf_submissions_id = :id",
            [
                'status'  => $status,
                'by'      => $handled ? ((int) ($this->core->session['user']['user_id'] ?? 0) ?: null) : null,
                'handled' => $handled ? 1 : 0,
                'id'      => $id,
            ]
        );

        $this->core->set_message_success('Marked ' . strtolower(self::STATUSES[$status]) . '.');
        $this->go($this->base_url . ($handled ? '/inbox' : '/message/' . $id));

        return true;
    }

    /** Email a message again: one that failed, or one rescued from spam. */
    public function resend($get, $post)
    {
        if (!$this->may_read()) {
            $this->core->set_message_error('You do not have permission to change contact form messages.');
            return false;
        }

        $id   = (int) ($post['cf_submissions_id'] ?? 0);
        $row  = $this->get_submission($id);
        $form = $row ? $this->get_form((int) $row['cf_forms_id']) : null;

        if (!$row || !$form) {
            $this->core->set_message_error('That message, or the form it came from, could not be found.');
            return false;
        }

        $ok = $this->notify($form, (array) json_decode((string) $row['data'], true), $id, $row['sender_email'] ?: null);

        //flash messages are printed raw (Core::get_message()), so anything stored
        //goes in escaped: a form admin chooses these addresses
        $ok ? $this->core->set_message_success('Sent to ' . self::e(implode(', ', array_filter(Mailer::addresses((string) $form['recipients']), [self::class, 'plain_email']))) . '.')
            : $this->core->set_message_error('It could not be sent. The reason is shown on the message.');
        $this->go($this->base_url . '/message/' . $id);

        return $ok;
    }

    /* =========================================================================
     * BUILDING FORMS
     * ---------------------------------------------------------------------- */

    private function forms_list(): ListView
    {
        return new ListView(__CLASS__, 'ajax_forms_list', self::FORMS_TARGET,
            ['title' => 'Form', 'messages' => 'Messages', 'latest' => 'Latest'],
            'title', 'asc', 25, 'forms');
    }

    public function rout_forms($get = [], $post = [])
    {
        if (!$this->may_manage()) {
            return $this->login_prompt('You do not have permission to change contact forms.');
        }

        $list    = $this->forms_list();
        $content = $this->template('forms.twig', [
            'search_box'  => $list->search_box(ListView::query($this->get), 'Search forms', [], 'Search forms'),
            'list_target' => $list->target(),
            'list_html'   => $this->forms_table($this->get),
        ]);

        return $this->page_wrapper($content, $this->head('forms'));
    }

    public function ajax_forms_list($get, $post): string
    {
        if (!$this->may_manage()) {
            return $this->login_prompt('You do not have permission to change contact forms.');
        }

        return $this->forms_table($post);
    }

    private function forms_table(array $src): string
    {
        $rows = $this->db->select(
            "SELECT f.cf_forms_id, f.title, f.recipients, f.is_active,
                    (SELECT COUNT(*) FROM cf_submissions s WHERE s.cf_forms_id = f.cf_forms_id AND s.deleted = 0 AND s.status <> 'spam') AS messages,
                    (SELECT COUNT(*) FROM cf_submissions s WHERE s.cf_forms_id = f.cf_forms_id AND s.deleted = 0 AND s.status = 'new') AS fresh,
                    (SELECT MAX(s.date_created) FROM cf_submissions s WHERE s.cf_forms_id = f.cf_forms_id AND s.deleted = 0 AND s.status <> 'spam') AS latest,
                    (SELECT COUNT(*) FROM cf_fields d WHERE d.cf_forms_id = f.cf_forms_id AND d.deleted = 0) AS fields
               FROM cf_forms f WHERE f.deleted = 0",
            []
        ) ?: [];

        $pages = $this->pages_by_form();

        foreach ($rows as $k => $row) {
            $rows[$k]['pages'] = $pages[(int) $row['cf_forms_id']] ?? [];
            $rows[$k]['edit']  = $this->base_url . '/form/' . (int) $row['cf_forms_id'];
            $rows[$k]['inbox'] = $this->base_url . '/inbox?status=all&form=' . (int) $row['cf_forms_id'];
            $rows[$k]['latest_label'] = $row['latest'] ? date('M j, Y', strtotime((string) $row['latest'])) : '';
        }

        $list = $this->forms_list();
        $win  = $list->window(
            $rows,
            $src,
            static fn(array $r): array => [$r['title'], (string) $r['recipients']],
            [
                'title'    => static fn(array $r) => (string) $r['title'],
                'messages' => static fn(array $r) => (int) $r['messages'],
                'latest'   => static fn(array $r) => (string) $r['latest'],
            ],
            'cf_forms_id'
        );

        return $this->template('forms-table.twig', [
            'rows'     => $win['rows'],
            'searched' => Search::has_terms(ListView::query($src)),
            'list'     => $list->controls($win['state']),
            'useclass' => __CLASS__,
            'action'   => $this->base_url . '/forms',
        ]);
    }

    /** The pages that picked each form in the page editor: form id => [{title, url}]. */
    private function pages_by_form(): array
    {
        $out = [];
        foreach ($this->db->select("SELECT page_title, page_url, module_vars FROM core_pages WHERE module_id = 'ContactForm' AND deleted = 0", []) ?: [] as $page) {
            $vars = \estuaries\Utilities::module_vars_to_array($page['module_vars'] ?? null);
            $pick = (int) ($vars['views'][0] ?? $vars[0] ?? 0);
            if ($pick > 0) {
                $out[$pick][] = ['title' => strip_tags((string) $page['page_title']), 'url' => '/' . ltrim((string) $page['page_url'], '/')];
            }
        }

        return $out;
    }

    public function rout_form_new($get = [], $post = [])
    {
        if (!$this->may_manage()) {
            return $this->login_prompt('You do not have permission to change contact forms.');
        }

        $defaults = [
            ['cf_fields_id' => '', 'label' => 'Your name', 'field_type' => 'text', 'options' => '', 'help' => '', 'is_required' => 1],
            ['cf_fields_id' => '', 'label' => 'Email address', 'field_type' => 'email', 'options' => '', 'help' => 'So we can reply.', 'is_required' => 1],
            ['cf_fields_id' => '', 'label' => 'Message', 'field_type' => 'textarea', 'options' => '', 'help' => '', 'is_required' => 1],
        ];

        return $this->builder_page(['cf_forms_id' => 0, 'title' => '', 'intro' => '', 'recipients' => '', 'subject_prefix' => '',
            'thank_you' => '', 'submit_label' => '', 'is_active' => 1], $defaults);
    }

    public function rout_form($get = [], $post = [])
    {
        if (!$this->may_manage()) {
            return $this->login_prompt('You do not have permission to change contact forms.');
        }

        $form = $this->get_form((int) ($get['id'] ?? 0));

        if (!$form) {
            return $this->page_wrapper("<div class='cf-card'><p>That form could not be found. It may have been deleted.</p></div>",
                ['title' => 'Not found', 'breadcrumb' => [['label' => 'Forms', 'href' => $this->base_url . '/forms'], ['label' => 'Not found']], 'compact' => true]);
        }

        return $this->builder_page($form, $this->get_fields((int) $form['cf_forms_id']));
    }

    private function builder_page(array $form, array $fields): string
    {
        $id    = (int) $form['cf_forms_id'];
        $stash = $this->take_stash('form:' . $id);
        //the heading names the form as saved, not as typed: a refused save may
        //have emptied the name
        $saved_title = trim((string) ($form['title'] ?? '')) ?: 'Untitled form';

        if ($stash) {
            $form   = array_merge($form, (array) ($stash['form'] ?? []));
            $fields = (array) ($stash['fields'] ?? []);
        }

        $content = $this->template('form-edit.twig', [
            'form'     => $form,
            'fields'   => array_values($fields),
            'errors'   => (array) ($stash['errors'] ?? []),
            'types'    => self::FIELD_TYPES,
            'choice_types' => self::CHOICE_TYPES,
            'useclass' => __CLASS__,
            'action'   => $this->base_url . ($id ? '/form/' . $id : '/form/new'),
            'cancel'   => $this->base_url . '/forms',
            'pages'    => $id ? ($this->pages_by_form()[$id] ?? []) : [],
        ]);

        return $this->page_wrapper($content, [
            'title'      => $id ? 'Edit ' . $saved_title : 'New form',
            'breadcrumb' => [['label' => 'Forms', 'href' => $this->base_url . '/forms'], ['label' => $id ? $saved_title : 'New']],
            'compact'    => true,
        ]);
    }

    /**
     * Save a form and its fields together. Fields arrive in display order; one
     * with an id of this form's is updated, one without is added, and one of
     * this form's that did not arrive is removed (soft: past messages carry
     * their own copy of it anyway).
     */
    public function save_form($get, $post)
    {
        if (!$this->may_manage()) {
            $this->core->set_message_error('You do not have permission to change contact forms.');
            return false;
        }

        $id       = (int) ($post['cf_forms_id'] ?? 0);
        $existing = $id > 0 ? $this->get_form($id) : null;

        if ($id > 0 && !$existing) {
            $this->core->set_message_error('That form could not be found. It may have been deleted.');
            return false;
        }

        [$form, $fields, $errors] = $this->clean_builder_post($post);

        if ($errors) {
            $this->put_stash('form:' . $id, ['form' => $form, 'fields' => $fields, 'errors' => $errors]);
            return false;
        }

        $this->db->begin_transaction();

        if ($existing) {
            $saved = $this->db->update(
                "UPDATE cf_forms SET title = :title, intro = :intro, recipients = :recipients, subject_prefix = :subject_prefix,
                        thank_you = :thank_you, submit_label = :submit_label, is_active = :is_active
                  WHERE cf_forms_id = :id",
                $form + ['id' => $id]
            );
        } else {
            $id = (int) $this->db->insert(
                "INSERT INTO cf_forms (title, intro, recipients, subject_prefix, thank_you, submit_label, is_active, created_by)
                 VALUES (:title, :intro, :recipients, :subject_prefix, :thank_you, :submit_label, :is_active, :by)",
                $form + ['by' => (int) ($this->core->session['user']['user_id'] ?? 0) ?: null],
                true
            );
            $saved = $id > 0;
        }

        $mine = [];
        if ($existing) {
            foreach ($this->db->select("SELECT cf_fields_id FROM cf_fields WHERE cf_forms_id = :id AND deleted = 0", ['id' => $id]) ?: [] as $row) {
                $mine[(int) $row['cf_fields_id']] = true;
            }
        }

        $kept = [];
        foreach ($saved ? $fields : [] as $order => $field) {
            $values = [
                'label'       => $field['label'],
                'field_type'  => $field['field_type'],
                'options'     => in_array($field['field_type'], self::CHOICE_TYPES, true) ? $field['options'] : null,
                'help'        => $field['help'] !== '' ? $field['help'] : null,
                'is_required' => $field['is_required'],
                'sort_order'  => $order,
            ];
            $fid = (int) $field['cf_fields_id'];

            if ($fid > 0 && isset($mine[$fid])) {
                $saved = $this->db->update(
                    "UPDATE cf_fields SET label = :label, field_type = :field_type, options = :options, help = :help,
                            is_required = :is_required, sort_order = :sort_order WHERE cf_fields_id = :fid",
                    $values + ['fid' => $fid]
                ) && $saved;
                $kept[$fid] = true;
            } else {
                $saved = $this->db->insert(
                    "INSERT INTO cf_fields (cf_forms_id, label, field_type, options, help, is_required, sort_order)
                     VALUES (:form, :label, :field_type, :options, :help, :is_required, :sort_order)",
                    $values + ['form' => $id]
                ) && $saved;
            }
        }

        foreach (array_keys(array_diff_key($mine, $kept)) as $gone) {
            $saved = $this->db->update("UPDATE cf_fields SET deleted = 1 WHERE cf_fields_id = :fid", ['fid' => $gone]) && $saved;
        }

        if (!$saved) {
            $this->db->roll_back();
            $this->put_stash('form:' . (int) ($existing['cf_forms_id'] ?? 0), ['form' => $form, 'fields' => $fields,
                'errors' => ['form' => 'The database refused the save. Nothing was changed.']]);
            return false;
        }

        $this->db->commit();
        $this->core->set_message_success($existing ? 'Form saved.' : 'The form is ready. Attach it to a page in that page\'s settings.');
        $this->go($this->base_url . '/forms');

        return true;
    }

    /**
     * The builder's post, cleaned, and what is wrong with it.
     *
     * @return array{0:array,1:array,2:array} the form's columns, its fields in
     *         order, and the errors (form-level by name, a field's as field.N)
     */
    public function clean_builder_post(array $post): array
    {
        $in     = (array) ($post['form'] ?? []);
        $errors = [];

        $form = [
            'title'          => trim((string) ($in['title'] ?? '')),
            'intro'          => trim((string) ($in['intro'] ?? '')) ?: null,
            'recipients'     => '',
            'subject_prefix' => mb_substr(trim((string) ($in['subject_prefix'] ?? '')), 0, 100) ?: null,
            'thank_you'      => trim((string) ($in['thank_you'] ?? '')) ?: null,
            'submit_label'   => mb_substr(trim((string) ($in['submit_label'] ?? '')), 0, 60) ?: null,
            'is_active'      => !empty($in['is_active']) ? 1 : 0,
        ];

        if ($form['title'] === '') {
            $errors['title'] = 'Give the form a name.';
        } elseif (mb_strlen($form['title']) > 200) {
            $errors['title'] = 'Keep the name under 200 characters.';
        }

        $typed = array_values(array_filter(array_map('trim', preg_split('/[\s,;]+/', (string) ($in['recipients'] ?? '')))));
        $bad   = array_values(array_filter($typed, static fn($a) => !self::plain_email($a)));
        $form['recipients'] = implode(', ', $typed);

        if ($bad) {
            $errors['recipients'] = 'Not an email address: ' . implode(', ', $bad) . '.';
        } elseif (!$typed) {
            $errors['recipients'] = 'Name at least one address to send messages to.';
        } elseif (mb_strlen($form['recipients']) > 1000) {
            $errors['recipients'] = 'That is too many addresses for one form.';
        }

        $fields = [];
        foreach ((array) ($post['fields'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = (string) ($row['field_type'] ?? 'text');
            $fields[] = [
                'cf_fields_id' => (int) ($row['cf_fields_id'] ?? 0) ?: '',
                'label'        => mb_substr(trim((string) ($row['label'] ?? '')), 0, 200),
                'field_type'   => isset(self::FIELD_TYPES[$type]) ? $type : 'text',
                'options'      => implode("\n", self::choices((string) ($row['options'] ?? ''))),
                'help'         => mb_substr(trim((string) ($row['help'] ?? '')), 0, 500),
                'is_required'  => !empty($row['is_required']) ? 1 : 0,
            ];
        }

        if (!$fields) {
            $errors['fields'] = 'A form needs at least one field.';
        }

        foreach ($fields as $n => $field) {
            if ($field['label'] === '') {
                $errors['field.' . $n] = 'Field ' . ($n + 1) . ' needs a label.';
            } elseif (in_array($field['field_type'], self::CHOICE_TYPES, true) && $field['options'] === '') {
                $errors['field.' . $n] = '"' . $field['label'] . '" needs its choices, one per line.';
            }
        }

        return [$form, $fields, $errors];
    }

    /** Delete a form. Its messages stay in the inbox; a page that showed it shows nothing. */
    public function delete_form($get, $post)
    {
        if (!$this->may_manage()) {
            $this->core->set_message_error('You do not have permission to change contact forms.');
            return false;
        }

        $form = $this->get_form((int) ($post['cf_forms_id'] ?? 0));

        if (!$form) {
            $this->core->set_message_error('That form could not be found. It may have been deleted already.');
            return false;
        }

        $this->db->update("UPDATE cf_forms SET deleted = 1, is_active = 0 WHERE cf_forms_id = :id", ['id' => (int) $form['cf_forms_id']]);
        $this->core->set_message_success('"' . self::e($form['title']) . '" was deleted. Its messages are still in the inbox.');
        $this->go($this->base_url . '/forms');

        return true;
    }

    /* =========================================================================
     * THE PAGE AROUND IT
     * ---------------------------------------------------------------------- */

    private function head(string $current): array
    {
        $tabs = [['label' => 'Inbox', 'href' => $this->base_url . '/inbox', 'current' => $current === 'inbox']];

        if ($this->may_manage()) {
            $tabs[] = ['label' => 'Forms', 'href' => $this->base_url . '/forms', 'current' => $current === 'forms'];
        }

        return [
            'title'    => 'Contact forms',
            'subtitle' => 'What people send through the site, and the forms they send it with',
            'actions'  => $this->may_manage() ? [['label' => 'New form', 'href' => $this->base_url . '/form/new', 'primary' => true]] : [],
            'tabs'     => $tabs,
            'back'     => $this->base_url,
        ];
    }

    public function page_wrapper($content = null, array $head = []): string
    {
        $head += ['title' => 'Contact forms'];

        return $this->template('page-wrapper.twig', ['content' => $content, 'head' => $head]);
    }

    private function put_stash(string $key, array $value): void
    {
        $_SESSION[self::STASH] = ['key' => $key] + $value;
    }

    private function take_stash(string $key): array
    {
        $stash = $_SESSION[self::STASH] ?? null;

        if (!is_array($stash) || ($stash['key'] ?? '') !== $key) {
            return [];
        }

        unset($_SESSION[self::STASH]);
        unset($stash['key']);

        return $stash;
    }
}
