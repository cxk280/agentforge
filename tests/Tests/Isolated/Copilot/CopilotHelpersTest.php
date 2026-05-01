<?php

/**
 * Isolated tests for AgentForge Co-Pilot view helpers.
 *
 * Covers cp_format_provider_name, cp_initials, cp_decode_log_comment —
 * the three functions in interface/main/copilot_helpers.php. They use
 * only plain PHP and no DB, so they fit the isolated-test profile.
 *
 * @package   OpenEMR
 * @author    AgentForge / Claude Code
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Tests\Isolated\Copilot;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../../../../interface/main/copilot_helpers.php';

class CopilotHelpersTest extends TestCase
{
    /* ─── cp_format_provider_name ─── */

    public function testFormatProviderName_NullReturnsUnknown(): void
    {
        $this->assertSame('Unknown', cp_format_provider_name(null));
    }

    public function testFormatProviderName_EmptyArrayReturnsUnknown(): void
    {
        $this->assertSame('Unknown', cp_format_provider_name([]));
    }

    public function testFormatProviderName_AdminUsernameReturnsSiteAdministrator(): void
    {
        $u = ['username' => 'admin', 'fname' => '', 'lname' => 'Administrator', 'title' => null];
        $this->assertSame('Site Administrator', cp_format_provider_name($u));
    }

    public function testFormatProviderName_AdminLnameReturnsSiteAdministrator(): void
    {
        $u = ['username' => 'someone', 'fname' => '', 'lname' => 'Administrator', 'title' => null];
        $this->assertSame('Site Administrator', cp_format_provider_name($u));
    }

    public function testFormatProviderName_AdminFnamePrefixed(): void
    {
        $u = ['username' => 'davis', 'fname' => 'Admin', 'lname' => 'davis', 'title' => null];
        $this->assertSame('davis (admin)', cp_format_provider_name($u));
    }

    #[DataProvider('doctorTitleProvider')]
    public function testFormatProviderName_DoctorTitlesGetDrPrefix(string $title): void
    {
        $u = ['username' => 'erivera', 'fname' => 'Eduardo', 'lname' => 'Rivera', 'title' => $title];
        $this->assertSame("Dr. Eduardo Rivera, $title", cp_format_provider_name($u));
    }

    /**
     * @return array<string, array{string}>
     *
     * @codeCoverageIgnore Data providers run before coverage instrumentation starts.
     */
    public static function doctorTitleProvider(): array
    {
        return [
            'MD'  => ['MD'],
            'DO'  => ['DO'],
            'DDS' => ['DDS'],
            'DMD' => ['DMD'],
            'DPM' => ['DPM'],
            'DC'  => ['DC'],
            'OD'  => ['OD'],
            'NP'  => ['NP'],
            'PA'  => ['PA'],
            'PHD' => ['PHD'],
        ];
    }

    public function testFormatProviderName_NurseTitleNoDrPrefix(): void
    {
        $u = ['username' => 'schoi', 'fname' => 'Sandra', 'lname' => 'Choi', 'title' => 'RN'];
        $this->assertSame('Sandra Choi, RN', cp_format_provider_name($u));
    }

    public function testFormatProviderName_NoTitlePlainName(): void
    {
        $u = ['username' => 'mnunez', 'fname' => 'Maria', 'lname' => 'Nunez', 'title' => ''];
        $this->assertSame('Maria Nunez', cp_format_provider_name($u));
    }

    public function testFormatProviderName_BlankNamesFallToUsername(): void
    {
        $u = ['username' => 'someuser', 'fname' => '', 'lname' => '', 'title' => null];
        $this->assertSame('someuser', cp_format_provider_name($u));
    }

    public function testFormatProviderName_MissingKeysSafe(): void
    {
        $u = ['fname' => 'Lin', 'lname' => 'Lee']; // no username, no title
        $this->assertSame('Lin Lee', cp_format_provider_name($u));
    }

    public function testFormatProviderName_TitleCaseInsensitive(): void
    {
        $u = ['username' => 'doc', 'fname' => 'Karen', 'lname' => 'Kim', 'title' => 'md'];
        $this->assertSame('Dr. Karen Kim, md', cp_format_provider_name($u));
    }

    /* ─── cp_initials ─── */

    public function testInitials_NullReturnsQuestion(): void
    {
        $this->assertSame('??', cp_initials(null));
    }

    public function testInitials_EmptyArrayReturnsQuestion(): void
    {
        $this->assertSame('??', cp_initials([]));
    }

    public function testInitials_FromFnameAndLname(): void
    {
        $u = ['fname' => 'Eduardo', 'lname' => 'Rivera', 'username' => 'erivera'];
        $this->assertSame('ER', cp_initials($u));
    }

    public function testInitials_FromUsernameWhenNamesEmpty(): void
    {
        $u = ['fname' => '', 'lname' => '', 'username' => 'admin'];
        $this->assertSame('AD', cp_initials($u));
    }

    public function testInitials_FromOnlyFname(): void
    {
        $u = ['fname' => 'Karen', 'lname' => '', 'username' => ''];
        $this->assertSame('K', cp_initials($u));
    }

    public function testInitials_AlwaysUppercase(): void
    {
        $u = ['fname' => 'maria', 'lname' => 'nunez'];
        $this->assertSame('MN', cp_initials($u));
    }

    /* ─── cp_decode_log_comment ─── */

    public function testDecodeLogComment_EmptyReturnsDash(): void
    {
        $this->assertSame('—', cp_decode_log_comment(''));
        $this->assertSame('—', cp_decode_log_comment('   '));
    }

    public function testDecodeLogComment_DecodesUrlPath(): void
    {
        $b64 = base64_encode('/interface/super/copilot_audit.php');
        $this->assertSame('/interface/super/copilot_audit.php', cp_decode_log_comment($b64));
    }

    public function testDecodeLogComment_DecodesSqlFragment(): void
    {
        $b64 = base64_encode('SELECT name FROM facility WHERE service_location = 1');
        $this->assertSame(
            'SELECT name FROM facility WHERE service_location = 1',
            cp_decode_log_comment($b64)
        );
    }

    public function testDecodeLogComment_PassesThroughPlainText(): void
    {
        // "User logged in" is not valid base64 (has spaces and length not %4),
        // so it should pass through unchanged.
        $this->assertSame('User logged in', cp_decode_log_comment('User logged in'));
    }

    public function testDecodeLogComment_TruncatesLongStrings(): void
    {
        $long = str_repeat('a', 500);
        $b64  = base64_encode($long);
        $out  = cp_decode_log_comment($b64, 100);
        $this->assertLessThanOrEqual(101, mb_strlen($out)); // 100 + ellipsis
        $this->assertStringEndsWith('…', $out);
    }

    public function testDecodeLogComment_CollapsesWhitespace(): void
    {
        $msg = "line1\n\nline2\t\tline3";
        $this->assertSame('line1 line2 line3', cp_decode_log_comment($msg));
    }
}
