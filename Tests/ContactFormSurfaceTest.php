<?php
declare(strict_types=1);

namespace estuaries_modules\ContactForm\Tests;

use estuaries\Core;
use estuaries_modules\ContactForm\ContactForm;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\FakeDB;
use Tests\Support\Paths;

/**
 * What a request can reach on ContactForm, and that everything but the public
 * form refuses somebody without the module's roles.
 *
 * PostRouter builds the class straight from the request and never passes
 * through a page, so each handler's own check is the only one there is.
 */
final class ContactFormSurfaceTest extends TestCase
{
    private array $saved_session = [];

    protected function setUp(): void
    {
        $this->saved_session = $_SESSION ?? [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->saved_session;
    }

    public function testTheSurfaceIsExactlyWhatThePagesUse(): void
    {
        self::assertSame([
            'submit_message', 'ajax_inbox_list', 'ajax_forms_list', 'set_status', 'resend', 'save_form', 'delete_form',
        ], ContactForm::REQUEST_METHODS);
    }

    /** Every method the templates post, and every ListView's redraw, is declared. */
    public function testEveryMethodThePagesPostIsDeclared(): void
    {
        $named = [];
        foreach (glob(Paths::module('ContactForm') . '/assets/templates/*.twig') as $file) {
            preg_match_all('/name="usemethod"\s+value="(\w+)"/', file_get_contents($file), $m);
            $named = array_merge($named, $m[1]);
        }
        preg_match_all("/new ListView\(__CLASS__, '(ajax_\w+)'/", file_get_contents(Paths::module('ContactForm') . '/ContactForm.php'), $m);
        $named = array_unique(array_merge($named, $m[1]));

        self::assertNotEmpty($named);
        foreach ($named as $method) {
            self::assertContains($method, ContactForm::REQUEST_METHODS, $method);
        }
    }

    public static function staffHandlers(): array
    {
        return array_map(static fn($m) => [$m], array_values(array_diff(ContactForm::REQUEST_METHODS, ['submit_message'])));
    }

    #[DataProvider('staffHandlers')]
    public function testANobodyIsRefusedAndNothingIsWritten(string $method): void
    {
        $db   = new FakeDB();
        $core = (new ReflectionClass(Core::class))->newInstanceWithoutConstructor();
        $core->db = $db;
        $core->session = &$_SESSION;

        $m = (new ReflectionClass(ContactForm::class))->newInstanceWithoutConstructor();
        $m->core = $core;
        $m->db = $db;
        $m->get = [];
        $m->post = ['action' => 'ajax'];
        $m->base_url = '/contact/m~';
        $m->set_roles([]);
        $m->mail_sender = static function () { throw new \LogicException('nothing may be emailed'); };

        $answer = $m->$method([], ['cf_submissions_id' => '1', 'cf_forms_id' => '1', 'status' => 'deleted',
            'form' => ['title' => 'x', 'recipients' => 'a@example.org'], 'fields' => [['label' => 'x', 'field_type' => 'text']]]);

        self::assertSame([], $db->calls, "{$method} touched the database for a nobody");
        self::assertTrue($answer === false || (is_string($answer) && str_contains($answer, 'permission')), $method);
    }

    /** Choosing a form in the inbox filter must not reload the page by itself (WCAG 3.2.2). */
    public function testNoControlChangesThePageOnInput(): void
    {
        foreach (glob(Paths::module('ContactForm') . '/assets/templates/*.twig') as $file) {
            self::assertStringNotContainsString('onchange', file_get_contents($file), basename($file));
        }
    }

    public function testInboxReadersMayNotBuildForms(): void
    {
        $m = (new ReflectionClass(ContactForm::class))->newInstanceWithoutConstructor();
        $m->is_admin = false;
        $m->is_inbox = true;

        self::assertTrue($m->may_read());
        self::assertFalse($m->may_manage());
    }
}
