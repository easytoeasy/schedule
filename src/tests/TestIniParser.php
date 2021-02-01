<?php  declare(strict_types=1);

namespace pzr\tests;

use PHPUnit\Framework\TestCase;
use pzr\schedule\Config;
use pzr\schedule\Helper;
use pzr\schedule\IniParser;
use pzr\schedule\sender\Sender;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

final class TestIniParser extends TestCase
{

    public function testParser()
    {
        $config = IniParser::getConfig();
        $this->assertNotEmpty($config);
        // var_export($config);
    }

}