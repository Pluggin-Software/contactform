<?php
declare(strict_types=1);

namespace estuaries_modules\ContactForm\Tests;

use estuaries\Core;
use estuaries\MailFailure;
use estuaries\Modules;
use estuaries_modules\ContactForm\ContactForm;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\FakeDB;
use Tests\Support\Paths;

/**
 * ContactForm 1.0.0: what a message must be to be kept, what keeps spam out,
 * who is emailed, and how the builder saves a form's fields.
 *
 * Nothing here reaches a mail server: every module is built with a recording
 * mail_sender, and the default sender is never constructed.
 */
final class ContactFormTest extends TestCase
{
    private array $saved_session = [];
    private array $saved_server = [];
    private FakeDB $db;
    private Core $core;
    /** @var array<int,array{to:array,subject:string,html:string,reply_to:?string}> */
    private array $mail = [];
    private $mail_answer = true;
    /** @var array<int,string> where each handler sent the browser */
    private array $redirects = [];
    /** @var array<int,array> the tasks raised */
    private array $tasks = [];
    private int $now = 1_800_000_000;

    protected function setUp(): void
    {
        $this->saved_session = $_SESSION ?? [];
        $this->saved_server  = $_SERVER;
        $_SESSION = [];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_SERVER['REQUEST_URI'] = '/contact?x=1';
        $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 test';
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->saved_session;
        $_SERVER  = $this->saved_server;
        MailFailure::$task_raiser = null;
        (new ReflectionClass(Modules::class))->getProperty('enabled_map')->setValue(null, null);
    }

    private function module(bool $admin = false, bool $inbox = false): ContactForm
    {
        $this->db = new FakeDB();

        $this->core = (new ReflectionClass(Core::class))->newInstanceWithoutConstructor();
        $this->core->db = $this->db;
        $this->core->session = &$_SESSION;
        $this->core->site = ['site_name' => 'Test'];
        $this->core->app_root = Paths::web();
        $this->core->full_base_web_url = 'https://example.test';

        $m = (new ReflectionClass(ContactForm::class))->newInstanceWithoutConstructor();
        $m->core = $this->core;
        $m->db = $this->db;
        $m->session = $_SESSION;
        $m->get = [];
        $m->post = [];
        $m->is_admin = $admin;
        $m->is_inbox = $admin || $inbox;
        $m->module_path = Paths::module('ContactForm');
        $m->base_url = '/contact/m~';
        $m->module_page_vars = [];
        $m->clock = fn(): int => $this->now;
        $this->redirects = [];
        $this->tasks = [];
        $m->redirector = function (string $url): void {
            $this->redirects[] = $url;
        };
        MailFailure::$task_raiser = function (array $task): void {
            $this->tasks[] = $task;
        };
        $m->mail_sender = function (array $to, string $subject, string $html, ?string $reply_to) {
            $this->mail[] = compact('to', 'subject', 'html', 'reply_to');
            return $this->mail_answer;
        };

        return $m;
    }

    private const FORM = [
        'cf_forms_id' => 4, 'title' => 'General enquiries', 'intro' => 'Write to us.', 'recipients' => 'a@example.org, b@example.org',
        'subject_prefix' => '[Web]', 'thank_you' => 'Thanks — we read everything.', 'submit_label' => '', 'is_active' => 1, 'deleted' => 0,
    ];

    private static function fields(): array
    {
        return [
            ['cf_fields_id' => 10, 'label' => 'Your name', 'field_type' => 'text', 'options' => null, 'help' => null, 'is_required' => 1],
            ['cf_fields_id' => 11, 'label' => 'Email address', 'field_type' => 'email', 'options' => null, 'help' => null, 'is_required' => 1],
            ['cf_fields_id' => 12, 'label' => 'Topic', 'field_type' => 'select', 'options' => "Grants\nEvents", 'help' => null, 'is_required' => 0],
            ['cf_fields_id' => 13, 'label' => 'Message', 'field_type' => 'textarea', 'options' => null, 'help' => null, 'is_required' => 1],
        ];
    }

    /** A form that is open, its fields, and $recent messages from this address. */
    private function script(array $form = self::FORM, int $recent = 0): void
    {
        $this->db->answerByQuery([
            'FROM cf_forms WHERE cf_forms_id' => $form,
            'FROM cf_fields WHERE cf_forms_id' => self::fields(),
            'FROM cf_submissions WHERE ip_address' => ['n' => $recent],
        ]);
    }

    private function post(array $f = [], array $extra = []): array
    {
        return $extra + ['cf_form' => '4', 'f' => $f + [
            10 => 'Ada Lovelace', 11 => 'ada@example.org', 12 => 'Grants', 13 => 'Hello there.',
        ]];
    }

    private function shown(int $seconds_ago = 30): void
    {
        $_SESSION['cf_rendered'][4] = $this->now - $seconds_ago;
    }

    private function writes(string $method): array
    {
        return $this->db->callsTo($method);
    }

    /* ------------------------------------------------------------ a message */

    public function testAGoodMessageIsKeptThenEmailedToTheFormsRecipients(): void
    {
        $m = $this->module();
        $this->script();
        $this->shown();

        self::assertTrue($m->submit_message([], $this->post()));

        $insert = $this->writes('insert')[0]['params'];
        self::assertSame(4, $insert['form']);
        self::assertSame('new', $insert['status']);
        self::assertSame('Ada Lovelace', $insert['name']);
        self::assertSame('ada@example.org', $insert['email']);
        self::assertSame('203.0.113.7', $insert['ip']);
        self::assertStringContainsString("Topic: Grants", $insert['text']);

        $data = json_decode($insert['data'], true);
        self::assertSame(['id' => 13, 'label' => 'Message', 'type' => 'textarea', 'value' => 'Hello there.'], $data[3]);

        self::assertCount(1, $this->mail);
        self::assertSame(['a@example.org', 'b@example.org'], $this->mail[0]['to']);
        self::assertSame('[Web] New message: General enquiries', $this->mail[0]['subject']);
        self::assertSame('ada@example.org', $this->mail[0]['reply_to']);
        self::assertStringContainsString('https://example.test/contact/m~/message/101', html_entity_decode($this->mail[0]['html']));

        $mailed = $this->writes('update')[0]['params'];
        self::assertSame(['m' => 1, 'e' => null, 'id' => 101], $mailed);

        self::assertTrue($_SESSION['cf_stash']['sent']);
        self::assertArrayNotHasKey(4, $_SESSION['cf_rendered'] ?? [], 'the clock is spent: a reload cannot send it again');
    }

    public function testWhatAVisitorTypesNeverBecomesMarkupInTheEmail(): void
    {
        $m = $this->module();
        $this->script();
        $this->shown();

        $m->submit_message([], $this->post([13 => '<script>alert(1)</script><a href="x">hi</a>']));

        self::assertStringNotContainsString('<script>', $this->mail[0]['html']);
        self::assertStringContainsString('&lt;script&gt;', $this->mail[0]['html']);
    }

    public function testAMessageTheMailServerRefusesIsKeptWithTheReason(): void
    {
        $m = $this->module();
        $this->script();
        $this->shown();
        $this->mail_answer = 'SMTP connect() failed.';

        self::assertTrue($m->submit_message([], $this->post()), 'the visitor is thanked: the message is safe in the inbox');

        self::assertCount(1, $this->writes('insert'));
        self::assertSame(['m' => 0, 'e' => 'SMTP connect() failed.', 'id' => 101], $this->writes('update')[0]['params']);
    }

    /* ------------------------------------------ after a send, and on failure */

    public function testASentMessageIsThankedOnThePageTheBrowserIsSentTo(): void
    {
        $m = $this->module();
        $this->script();
        $this->shown();

        self::assertTrue($m->submit_message([], $this->post()));
        self::assertSame(['/contact'], $this->redirects, 'back to the page, without its query');

        //the next request: the page the browser was sent to
        $m->module_page_vars = ['views' => [4]];
        $html = $m->rout_index();
        self::assertStringContainsString('data-cf-sent="4"', $html);
        self::assertStringContainsString('Thanks — we read everything.', $html);
        self::assertStringContainsString('<h2 class="cf-title">General enquiries</h2>', $html, 'the page keeps its title');
        self::assertStringContainsString('Replies will go to <strong dir="auto">ada@example.org</strong>', $html);
        self::assertStringContainsString('href="&#x2F;contact">Send another message', $html, 'back to the page, escaped as an attribute');
        self::assertStringNotContainsString('<form', $html);

        self::assertStringNotContainsString('data-cf-sent', $m->rout_index(), 'shown once: a reload brings the form back');
    }

    public function testTheThanksNamesNoAddressItCannotStandBehind(): void
    {
        $m = $this->module();
        $this->script();
        $this->shown();

        //no email field answered: nothing to reply to, so no line saying so
        $fields = array_values(array_filter(self::fields(), static fn($f) => $f['field_type'] !== 'email'));
        $this->db->answerByQuery(['FROM cf_fields WHERE cf_forms_id' => $fields]);
        self::assertTrue($m->submit_message([], $this->post()));

        $m->module_page_vars = ['views' => [4]];
        $html = $m->rout_index();
        self::assertStringContainsString('Message sent', $html);
        self::assertStringNotContainsString('Replies will go to', $html);
    }

    public function testTheReplyAddressIsEscapedOnTheThanks(): void
    {
        $m = $this->module();
        $this->script();
        $_SESSION['cf_stash'] = ['key' => 'message:4', 'sent' => true, 'reply_to' => '<img src=x onerror=alert(1)>@example.org'];

        $m->module_page_vars = ['views' => [4]];
        $html = $m->rout_index();

        self::assertStringNotContainsString('<img', $html);
        self::assertStringContainsString('&lt;img', $html);
    }

    /**
     * The Router renders the page in the same POST response after a handler
     * returns, and that render took the stash meant for the redirected page.
     * Every redirect must END the request, which only go() does.
     */
    public function testEveryRedirectEndsTheRequest(): void
    {
        $src = (string) file_get_contents(Paths::module('ContactForm') . '/ContactForm.php');
        self::assertSame(1, substr_count($src, "header('Location: "), 'only go() sends a Location header');

        $go = substr($src, strpos($src, 'private function go('));
        $go = substr($go, 0, strpos($go, "\n    }\n") + 6);
        self::assertMatchesRegularExpression("/header\\('Location: ' \\. \\\$url\\);\\s*exit;/", $go);
    }

    public function testAFailedNoticeRaisesOneTaskForEachSuperUser(): void
    {
        $m = $this->module();
        $this->script();
        $this->db->answerByQuery([
            'FROM user_registry ur' => [['user_id' => 1], ['user_id' => 5]],
            'FROM core_tasks'       => ['n' => 0],
        ]);
        $this->shown();
        $this->mail_answer = 'SMTP Error: Could not authenticate. <b>';

        self::assertTrue($m->submit_message([], $this->post()), 'the visitor is still thanked');

        self::assertCount(2, $this->tasks);
        self::assertSame([1, 5], array_column($this->tasks, 'assignee_user_id'));
        $task = $this->tasks[0];
        self::assertSame('mail', $task['application_id'], 'core\'s MailFailure, shared with every other email');
        self::assertSame(1, $task['assigner_user_id'], 'from themselves: no email, mail is what is broken');
        self::assertSame('https://example.test/contact/m~/message/101', $task['link'], 'the task opens the message, where Resend is');
        self::assertSame('Open the message', $task['link_text']);
        self::assertStringContainsString('press Resend', $task['description']);
        self::assertStringContainsString('Could not authenticate. &lt;b&gt;', $task['description']);
        self::assertStringContainsString('General enquiries', $task['description']);
        self::assertStringStartsWith('mail-failure-1-', $task['token']);

        $who = $this->db->callsTo('select');
        $roles = array_values(array_filter($who, static fn($c) => str_contains($c['query'], 'FROM user_registry ur')));
        self::assertStringContainsString('rl.user_roles_id = 1', $roles[0]['query']);
        self::assertStringContainsString('ur.active = 1', $roles[0]['query']);
    }

    public function testASuperUserWithAnOpenTaskIsNotGivenAnother(): void
    {
        $m = $this->module();
        $this->script();
        $this->db->answerByQuery([
            'FROM user_registry ur' => [['user_id' => 1]],
            'FROM core_tasks'       => ['n' => 1],
        ]);
        $this->shown();
        $this->mail_answer = 'SMTP connect() failed.';

        $m->submit_message([], $this->post());

        self::assertSame([], $this->tasks);
    }

    public function testASentNoticeRaisesNoTask(): void
    {
        $m = $this->module();
        $this->script();
        $this->db->answerByQuery(['FROM user_registry ur' => [['user_id' => 1]], 'FROM core_tasks' => ['n' => 0]]);
        $this->shown();

        $m->submit_message([], $this->post());

        self::assertSame([], $this->tasks);
    }

    public function testSpamIsNeitherMailedNorReported(): void
    {
        $m = $this->module();
        $this->script();
        $this->db->answerByQuery(['FROM user_registry ur' => [['user_id' => 1]], 'FROM core_tasks' => ['n' => 0]]);
        $this->shown();
        $this->mail_answer = 'SMTP connect() failed.';

        $m->submit_message([], $this->post([], [ContactForm::HONEYPOT => 'x']));

        self::assertSame([], $this->mail);
        self::assertSame([], $this->tasks);
    }

    public function testAFormWithNoRecipientsStillKeepsTheMessage(): void
    {
        $m = $this->module();
        $this->script(['recipients' => ''] + self::FORM);
        $this->shown();

        $m->submit_message([], $this->post());

        self::assertCount(1, $this->writes('insert'));
        self::assertSame([], $this->mail);
        self::assertSame(0, $this->writes('update')[0]['params']['m']);
    }

    public function testAMissingAnswerIsRefusedAndWhatWasTypedIsKept(): void
    {
        $m = $this->module();
        $this->script();
        $this->shown();

        self::assertFalse($m->submit_message([], $this->post([11 => 'not-an-address', 13 => ''])));

        self::assertSame([], $this->writes('insert'));
        self::assertSame([], $this->mail);
        self::assertSame('message:4', $_SESSION['cf_stash']['key']);
        self::assertSame('not-an-address', $_SESSION['cf_stash']['values'][11]);
        self::assertArrayHasKey(11, $_SESSION['cf_stash']['errors']);
        self::assertArrayHasKey(13, $_SESSION['cf_stash']['errors']);
    }

    /**
     * The form is autoescaped (ContactForm 1.0.6), and the aria attributes are
     * a string built in the template, so they are printed |raw: escaped, the
     * quotes would land in the page as &quot; and the attribute would be lost.
     */
    public function testARefusedFieldIsMarkedInvalidForScreenReaders(): void
    {
        $m = $this->module();
        $this->script();
        $this->shown();
        $m->submit_message([], $this->post([11 => 'not-an-address', 13 => '']));

        $m->module_page_vars = ['views' => [4]];
        $html = $m->rout_index();

        self::assertStringContainsString('aria-invalid="true"', $html);
        self::assertMatchesRegularExpression('/aria-describedby="[^"&]+_err"/', $html);
        self::assertStringNotContainsString('&quot;', $html);
    }

    public function testAClosedFormTakesNothing(): void
    {
        $m = $this->module();
        $this->script(['is_active' => 0] + self::FORM);
        $this->shown();

        self::assertFalse($m->submit_message([], $this->post()));
        self::assertSame([], $this->writes('insert'));
    }

    /* ------------------------------------------------------------ spam */

    public function testTheHoneypotKeepsTheMessageAsSpamAndAnswersAsIfItWent(): void
    {
        $m = $this->module();
        $this->script();
        $this->shown();

        self::assertTrue($m->submit_message([], $this->post([], [ContactForm::HONEYPOT => 'http://spam.example'])));

        $insert = $this->writes('insert')[0]['params'];
        self::assertSame('spam', $insert['status']);
        self::assertStringContainsString('hidden field', $insert['reason']);
        self::assertSame([], $this->mail, 'spam is never emailed');
        self::assertTrue($_SESSION['cf_stash']['sent'], 'the sender learns nothing');
    }

    public function testSendingFasterThanAPersonCouldIsKeptAsSpamWithTheTime(): void
    {
        $m = $this->module();
        $this->script();
        $this->shown(1);

        self::assertTrue($m->submit_message([], $this->post()));

        $insert = $this->writes('insert')[0]['params'];
        self::assertSame('spam', $insert['status']);
        self::assertSame('It was sent 1 second after the form appeared', $insert['reason']);
        self::assertSame([], $this->mail);
    }

    public function testEveryReasonIsRecorded(): void
    {
        $m = $this->module();
        $this->script();
        $this->shown(0);

        $m->submit_message([], $this->post([13 => 'https://a.example https://b.example https://c.example https://d.example'], [ContactForm::HONEYPOT => 'x']));

        self::assertSame(
            'It filled in a hidden field that only automated senders see; It was sent 0 seconds after the form appeared; It holds 4 links',
            $this->writes('insert')[0]['params']['reason']
        );
    }

    /** The audit's P0: an error page restarted the clock, so a quick fix and resend was thrown away as a bot. */
    public function testFixingAnErrorAndSendingAtOnceIsAPersonNotABot(): void
    {
        $m = $this->module();
        $m->module_page_vars = ['4'];
        $this->script();

        $this->shown(20);                       //the form appeared 20 s ago
        $m->submit_message([], $this->post([13 => '']));    //refused: no message
        $m->rout_index();                       //the error page renders...
        self::assertSame($this->now - 20, $_SESSION['cf_rendered'][4], '...without restarting the clock');

        $this->now += 1;                        //fixed and sent again a second later
        $m->submit_message([], $this->post());

        self::assertSame('new', $this->writes('insert')[0]['params']['status']);
        self::assertCount(1, $this->mail);
    }

    public function testADoubleClickSendsOneMessageAndThanksForBoth(): void
    {
        $m = $this->module();
        $this->script();
        $this->shown();

        self::assertTrue($m->submit_message([], $this->post()));
        $this->now += 2;
        self::assertTrue($m->submit_message([], $this->post()), 'the second press is answered as sent, not as a lost session');

        self::assertCount(1, $this->writes('insert'));
        self::assertCount(1, $this->mail);
        self::assertTrue($_SESSION['cf_stash']['sent']);
    }

    public function testAPostLongAfterTheLastSendIsNotTakenForADuplicate(): void
    {
        $m = $this->module();
        $this->script();
        $_SESSION['cf_sent_at'][4] = $this->now - ContactForm::DUPLICATE_SECONDS - 1;

        self::assertFalse($m->submit_message([], $this->post()));
        self::assertArrayHasKey('form', $_SESSION['cf_stash']['errors']);
    }

    public function testTheRateLimitComesBeforeAnythingIsKept(): void
    {
        $m = $this->module();
        $this->script(self::FORM, ContactForm::RATE_MAX);
        $this->shown();

        self::assertFalse($m->submit_message([], $this->post([], [ContactForm::HONEYPOT => 'x'])));
        self::assertSame([], $this->writes('insert'), 'a flood is bounded even when it trips the honeypot');
    }

    public function testTheMessageRecordsThePageItWasSentFrom(): void
    {
        $m = $this->module();
        $this->script();
        $this->shown();

        $m->submit_message([], $this->post([], ['cf_page' => '17']));

        self::assertSame(17, $this->writes('insert')[0]['params']['page']);
    }

    public function testTheHoneypotIsNotNamedLikeSomethingAutofillWouldFill(): void
    {
        self::assertDoesNotMatchRegularExpression('/web|url|site|name|mail|phone|tel|address|company/i', ContactForm::HONEYPOT);
    }

    public function testAPostForAFormNeverShownIsAskedToSendAgain(): void
    {
        $m = $this->module();
        $this->script();

        self::assertFalse($m->submit_message([], $this->post()));
        self::assertSame([], $this->writes('insert'));
        self::assertArrayHasKey('form', $_SESSION['cf_stash']['errors']);
        self::assertSame('Ada Lovelace', $_SESSION['cf_stash']['values'][10]);
    }

    public function testTooManyMessagesFromOneAddressAreRefused(): void
    {
        $m = $this->module();
        $this->script(self::FORM, ContactForm::RATE_MAX);
        $this->shown();

        self::assertFalse($m->submit_message([], $this->post()));
        self::assertSame([], $this->writes('insert'));
        self::assertStringContainsString('few minutes', $_SESSION['cf_stash']['errors']['form']);

        //measured on the database's clock: PHP and MySQL need not share a timezone
        $rate = array_values(array_filter($this->db->calls, static fn($c) => str_contains($c['query'], 'ip_address = :ip')))[0];
        self::assertStringContainsString('NOW() - INTERVAL :mins MINUTE', $rate['query']);
        self::assertSame(['ip' => '203.0.113.7', 'mins' => ContactForm::RATE_MINUTES], $rate['params']);
    }

    public function testAMessageFullOfLinksIsKeptAsSpamAndNotEmailed(): void
    {
        $m = $this->module();
        $this->script();
        $this->shown();

        $m->submit_message([], $this->post([13 => 'https://a.example https://b.example www.c.example [url=d]']));

        self::assertSame('spam', $this->writes('insert')[0]['params']['status']);
        self::assertSame([], $this->mail);
        self::assertSame([], $this->writes('update'), 'never tried: mailed stays NULL');
    }

    public function testAFewLinksAreFine(): void
    {
        $m = $this->module();
        $this->script();
        $this->shown();

        $m->submit_message([], $this->post([13 => 'See https://a.example and https://b.example']));

        self::assertSame('new', $this->writes('insert')[0]['params']['status']);
        self::assertCount(1, $this->mail);
    }

    /* ------------------------------------------------------------ checking answers */

    public function testEachKindOfFieldIsCheckedForWhatItIs(): void
    {
        $m = $this->module();
        $fields = [
            ['cf_fields_id' => 1, 'label' => 'Phone', 'field_type' => 'tel', 'options' => '', 'is_required' => 0],
            ['cf_fields_id' => 2, 'label' => 'Pick one', 'field_type' => 'radio', 'options' => "Yes\nNo", 'is_required' => 1],
            ['cf_fields_id' => 3, 'label' => 'Pick any', 'field_type' => 'checkboxes', 'options' => "Red\nBlue", 'is_required' => 1],
            ['cf_fields_id' => 4, 'label' => 'I agree', 'field_type' => 'checkbox', 'options' => '', 'is_required' => 1],
            ['cf_fields_id' => 5, 'label' => 'Subject', 'field_type' => 'text', 'options' => '', 'is_required' => 0],
        ];

        [, , $errors] = $m->validate($fields, [1 => 'call me', 2 => 'Maybe', 3 => ['Red', 'Green'], 5 => str_repeat('x', 501)]);
        self::assertSame([1, 2, 3, 4, 5], array_keys($errors));

        [$data, , $errors] = $m->validate($fields, [1 => '+1 (410) 555-0100 x12', 2 => 'No', 3 => ['Red', 'Blue'], 4 => '1', 5 => "two\nlines"]);
        self::assertSame([], $errors);
        self::assertSame('Red, Blue', $data[2]['value']);
        self::assertSame('Yes', $data[3]['value']);
        self::assertSame('two lines', $data[4]['value'], 'a one-line answer holds no newline');
    }

    public function testAnOptionalFieldLeftEmptyIsFine(): void
    {
        [$data, , $errors] = $this->module()->validate(
            [['cf_fields_id' => 9, 'label' => 'Phone', 'field_type' => 'tel', 'options' => '', 'is_required' => 0]], []
        );

        self::assertSame([], $errors);
        self::assertSame('', $data[0]['value']);
    }

    /* ------------------------------------------------------------ the page */

    public function testAPageShowsTheFormItPickedInThePageEditor(): void
    {
        $m = $this->module();
        $m->module_page_vars = ['7', 'page_url' => 'contact'];
        self::assertSame(7, $m->page_form_id());

        $m->module_page_vars = ['views' => ['8'], 'nav_links' => true];
        self::assertSame(8, $m->page_form_id());
    }

    public function testAPageThatPickedNothingShowsTheOnlyActiveForm(): void
    {
        $m = $this->module();
        $this->db->answerByQuery(['is_active = 1 LIMIT 2' => [['cf_forms_id' => 3]]]);
        self::assertSame(3, $m->page_form_id());

        $m = $this->module();
        $this->db->answerByQuery(['is_active = 1 LIMIT 2' => [['cf_forms_id' => 3], ['cf_forms_id' => 5]]]);
        self::assertNull($m->page_form_id(), 'two forms: the page has to say which');
    }

    public function testThePublicFormCarriesItsTokenTheHoneypotAndStartsTheClock(): void
    {
        $m = $this->module();
        $m->module_page_vars = ['4'];
        $this->script(['intro' => '<img src=x onerror=alert(1)>'] + self::FORM);

        $raw  = $m->rout_index();
        $html = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5);

        self::assertStringContainsString('name="usemethod" value="submit_message"', $html);
        self::assertStringContainsString("name='_token'", $html);
        self::assertStringContainsString('name="' . ContactForm::HONEYPOT . '"', $html);
        self::assertStringContainsString('name="f[13]"', $html);
        self::assertStringContainsString('&lt;img', $raw);
        self::assertStringNotContainsString('<img', $raw);
        self::assertStringContainsString('action="/contact"', $html);
        self::assertSame($this->now, $_SESSION['cf_rendered'][4]);
        self::assertStringNotContainsString('cf-toolbar', $html, 'a visitor sees no staff links');
    }

    public function testARefusedMessageListsEachProblemAndMarksTheFieldInvalid(): void
    {
        $m = $this->module();
        $m->module_page_vars = ['4', 'core_pages_id' => 17];
        $this->script();
        $_SESSION['cf_stash'] = ['key' => 'message:4', 'values' => [11 => 'nope'], 'errors' => [11 => 'That does not look like an email address.']];

        $html = html_entity_decode($m->rout_index(), ENT_QUOTES | ENT_HTML5);

        self::assertStringContainsString('<a href="#cf_f_11">Email address: That does not look like an email address.</a>', $html);
        self::assertMatchesRegularExpression('/id="cf_f_11"[^>]*aria-invalid="true"/', $html);
        self::assertDoesNotMatchRegularExpression('/id="cf_f_10"[^>]*aria-invalid/', $html);
        self::assertStringContainsString('name="cf_page" value="17"', $html);
    }

    public function testAfterSendingThePageSaysThankYouInsteadOfTheForm(): void
    {
        $m = $this->module();
        $m->module_page_vars = ['4'];
        $this->script();
        $_SESSION['cf_stash'] = ['key' => 'message:4', 'sent' => true];

        $html = $m->rout_index();

        self::assertStringContainsString('Thanks — we read everything.', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertArrayNotHasKey('cf_stash', $_SESSION, 'said once');
    }

    public function testARefusedMessageRendersAgainWithWhatWasTyped(): void
    {
        $m = $this->module();
        $m->module_page_vars = ['4'];
        $this->script();
        $_SESSION['cf_stash'] = ['key' => 'message:4', 'values' => [10 => 'Ada "the" Countess'], 'errors' => [11 => 'This is needed.']];

        $html = $m->rout_index();

        self::assertStringContainsString('value="Ada&#x20;&quot;the&quot;&#x20;Countess"', $html);
        self::assertStringContainsString('This is needed.', $html);
    }

    /* ------------------------------------------------------------ the builder */

    public function testTheBuilderNamesEverythingWrongWithAForm(): void
    {
        [, , $errors] = $this->module()->clean_builder_post([
            'form'   => ['title' => '', 'recipients' => 'ok@example.org, nope'],
            'fields' => [['label' => '', 'field_type' => 'text'], ['label' => 'Topic', 'field_type' => 'select', 'options' => "\n \n"]],
        ]);

        self::assertSame(['title', 'recipients', 'field.0', 'field.1'], array_keys($errors));
        self::assertStringContainsString('nope', $errors['recipients']);
    }

    public function testAFormNeedsAFieldAndSomewhereToSend(): void
    {
        [, , $errors] = $this->module()->clean_builder_post(['form' => ['title' => 'X', 'recipients' => '']]);

        self::assertArrayHasKey('recipients', $errors);
        self::assertArrayHasKey('fields', $errors);
    }

    public function testSavingKeepsMovesAddsAndRemovesFields(): void
    {
        $m = $this->module(true);
        $this->db->answerByQuery([
            'FROM cf_forms WHERE cf_forms_id' => self::FORM,
            'SELECT cf_fields_id FROM cf_fields' => [['cf_fields_id' => 10], ['cf_fields_id' => 11], ['cf_fields_id' => 12]],
        ]);

        $saved = $m->save_form([], [
            'cf_forms_id' => '4',
            'form'   => ['title' => 'General', 'recipients' => 'a@example.org; b@example.org', 'is_active' => '1'],
            'fields' => [
                ['cf_fields_id' => '11', 'label' => 'Email', 'field_type' => 'email', 'is_required' => '1'],
                ['cf_fields_id' => '', 'label' => 'Topic', 'field_type' => 'radio', 'options' => "A\nB\nA"],
                ['cf_fields_id' => '999', 'label' => 'Not ours', 'field_type' => 'nonsense'],
                ['cf_fields_id' => '10', 'label' => 'Name', 'field_type' => 'text'],
            ],
        ]);

        self::assertTrue($saved);

        $form = $this->writes('update')[0]['params'];
        self::assertSame('a@example.org, b@example.org', $form['recipients']);
        self::assertSame(4, $form['id']);

        $updates = array_slice($this->writes('update'), 1);
        self::assertSame([11, 0], [$updates[0]['params']['fid'], $updates[0]['params']['sort_order']]);
        self::assertSame([10, 3], [$updates[1]['params']['fid'], $updates[1]['params']['sort_order']]);
        self::assertStringContainsString('SET deleted = 1', $updates[2]['query']);
        self::assertSame(12, $updates[2]['params']['fid'], 'the field that did not come back is removed');

        $inserts = $this->writes('insert');
        self::assertCount(2, $inserts, 'a new row, and an id from another form is treated as new');
        self::assertSame("A\nB", $inserts[0]['params']['options']);
        self::assertSame('text', $inserts[1]['params']['field_type'], 'an unknown kind falls back to short text');
        self::assertNull($inserts[1]['params']['options']);
    }

    public function testARefusedSaveChangesNothingAndKeepsTheRows(): void
    {
        $m = $this->module(true);
        $this->db->answerByQuery(['FROM cf_forms WHERE cf_forms_id' => self::FORM]);

        self::assertFalse($m->save_form([], ['cf_forms_id' => '4', 'form' => ['title' => 'X', 'recipients' => 'bad'],
            'fields' => [['label' => 'Name', 'field_type' => 'text']]]));

        self::assertSame([], $this->writes('update'));
        self::assertSame([], $this->writes('insert'));
        self::assertSame('Name', $_SESSION['cf_stash']['fields'][0]['label']);
    }

    public function testARefusedSaveKeepsTheHeadingOnTheSavedName(): void
    {
        $m = $this->module(true);
        $this->db->answerByQuery(['FROM cf_forms WHERE cf_forms_id' => self::FORM, 'FROM cf_fields WHERE cf_forms_id' => self::fields(), 'FROM core_pages' => []]);
        $_SESSION['cf_stash'] = ['key' => 'form:4', 'form' => ['title' => ''], 'fields' => self::fields(), 'errors' => ['title' => 'Give the form a name.']];

        $html = $m->rout_form(['id' => 4]);

        self::assertStringContainsString('Edit General enquiries', $html);
        self::assertStringContainsString('Give the form a name.', $html);
    }

    /* ------------------------------------------------------------ security review, 2026-10-23 */

    /**
     * Flash messages are printed raw (Core::get_message()), and a form's title
     * is chosen by a [ContactForm] Admin, who is not a site admin: a title
     * carrying markup ran in the session of whoever deleted the form.
     */
    public function testDeletingAFormDoesNotRunItsTitle(): void
    {
        $m = $this->module(true);
        $this->db->answerByQuery(['FROM cf_forms WHERE cf_forms_id' => ['title' => '<img src=x onerror=alert(1)>'] + self::FORM]);

        $m->delete_form([], ['cf_forms_id' => '4']);

        $flash = (string) ($this->core->message['success'] ?? '');
        self::assertStringNotContainsString('<img src=x', $flash);
        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $flash);
    }

    public function testAResentMessageNamesItsRecipientsAsText(): void
    {
        $_SESSION['user'] = ['user_id' => 28];
        $m = $this->module(true);
        $this->db->answerByQuery([
            'FROM cf_submissions s LEFT JOIN' => ['cf_submissions_id' => 9, 'cf_forms_id' => 4, 'status' => 'spam', 'sender_email' => 'ada@example.org', 'data' => '[]'],
            'FROM cf_forms WHERE cf_forms_id' => ['recipients' => 'a@example.org'] + self::FORM,
        ]);

        self::assertTrue($m->resend([], ['cf_submissions_id' => '9']));
        self::assertSame('Sent to a@example.org.', $this->core->message['success'] ?? null);
    }

    public function testOnlyEverydayAddressesAreAccepted(): void
    {
        foreach (['ada@example.org', 'a.b+tag@sub.example.co.uk', "o'brien@example.ie"] as $ok) {
            self::assertTrue(ContactForm::plain_email($ok), $ok);
        }
        foreach (['"<img/src=x/onerror=alert(1)>"@x.com', '"john doe"@x.com', 'x@[127.0.0.1]', 'not-an-address', "a@b.com\r\nBcc: c@d.com"] as $bad) {
            self::assertFalse(ContactForm::plain_email($bad), $bad);
        }
    }

    public function testTheBuilderRefusesAQuotedRecipient(): void
    {
        [, , $errors] = $this->module()->clean_builder_post([
            'form'   => ['title' => 'X', 'recipients' => 'ok@example.org,"<img/src=x/onerror=alert(1)>"@x.com'],
            'fields' => [['label' => 'Name', 'field_type' => 'text']],
        ]);

        self::assertArrayHasKey('recipients', $errors);
    }

    public function testAVisitorsQuotedAddressIsRefusedNotUsedAsReplyTo(): void
    {
        $m = $this->module();
        $this->script();
        $this->shown();

        self::assertFalse($m->submit_message([], $this->post([11 => '"<svg onload=alert(1)>"@example.org'])));
        self::assertSame([], $this->writes('insert'));
        self::assertArrayHasKey(11, $_SESSION['cf_stash']['errors']);
    }

    /** A row saved before 1.0.1 may hold an address plain_email() now refuses: resend must not use it. */
    public function testAResendReChecksStoredAddresses(): void
    {
        $_SESSION['user'] = ['user_id' => 28];
        $m = $this->module(true);
        $this->db->answerByQuery([
            'FROM cf_submissions s LEFT JOIN' => ['cf_submissions_id' => 9, 'cf_forms_id' => 4, 'status' => 'new', 'sender_email' => '"<svg onload=alert(1)>"@example.org', 'data' => '[]'],
            'FROM cf_forms WHERE cf_forms_id' => ['recipients' => 'ok@example.org, "<img/src=x/onerror=alert(1)>"@x.com'] + self::FORM,
        ]);

        $m->resend([], ['cf_submissions_id' => '9']);

        self::assertSame(['ok@example.org'], $this->mail[0]['to']);
        self::assertNull($this->mail[0]['reply_to']);
        self::assertSame('Sent to ok@example.org.', $this->core->message['success'] ?? null);
    }

    /* ------------------------------------------------------------ the inbox */

    public function testMarkingHandledRecordsWhoAndWhen(): void
    {
        $_SESSION['user'] = ['user_id' => 28];
        $m = $this->module(false, true);
        $this->db->answerByQuery(['FROM cf_submissions s LEFT JOIN' => ['cf_submissions_id' => 9, 'status' => 'new']]);

        self::assertTrue($m->set_status([], ['cf_submissions_id' => '9', 'status' => 'handled']));

        $update = $this->writes('update')[0];
        self::assertSame(['status' => 'handled', 'by' => 28, 'handled' => 1, 'id' => 9], $update['params']);
        self::assertStringContainsString('date_handled = IF(:handled, NOW(), NULL)', $update['query'], 'the database stamps it, as it stamps date_created');
    }

    public function testAnUnknownStatusChangesNothing(): void
    {
        $m = $this->module(false, true);
        $this->db->answerByQuery(['FROM cf_submissions s LEFT JOIN' => ['cf_submissions_id' => 9, 'status' => 'new']]);

        self::assertFalse($m->set_status([], ['cf_submissions_id' => '9', 'status' => 'archived']));
        self::assertSame([], $this->writes('update'));
    }

    public function testThePreviewIsTheFirstLongAnswer(): void
    {
        self::assertSame('Long text here', ContactForm::preview([
            ['type' => 'text', 'value' => 'Ada'], ['type' => 'textarea', 'value' => "Long\n text   here"],
        ]));
        self::assertSame('Ada · ada@example.org', ContactForm::preview([
            ['type' => 'text', 'value' => 'Ada'], ['type' => 'email', 'value' => 'ada@example.org'], ['type' => 'checkbox', 'value' => 'No'],
        ]));
    }
}
