<?php

namespace fabianhaef\simpleform\tests\smoke;

use Craft;
use craft\web\Response;
use fabianhaef\simpleform\controllers\McpController;
use fabianhaef\simpleform\Plugin;
use SmokeTester;

/**
 * MCP endpoint smoke tests: the security-critical defaults of the machine API —
 * off by default (a disabled server 404s, leaking nothing), and token-required
 * (an enabled server refuses an unauthenticated request with 401). The full
 * authenticated JSON-RPC flow is covered by the integration suite.
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class McpEndpointSmokeCest extends BaseSmokeCest
{
    // =========================================================================
    // PUBLIC METHODS
    // =========================================================================

    public function testDisabledServerIsInvisible(SmokeTester $I): void
    {
        Plugin::getInstance()->getSettings()->enableMcp = false;

        $response = $this->callMcp(false);
        $I->assertSame(404, $response->statusCode, 'a disabled MCP server pretends not to exist');
    }

    public function testEnabledServerRefusesAnUnauthenticatedRequest(SmokeTester $I): void
    {
        Plugin::getInstance()->getSettings()->enableMcp = true;

        $response = $this->callMcp(true);
        $I->assertSame(401, $response->statusCode, 'an enabled MCP server requires a bearer token');

        // Restore the off-by-default state for any later test in the suite.
        Plugin::getInstance()->getSettings()->enableMcp = false;
    }

    // =========================================================================
    // PRIVATE METHODS
    // =========================================================================

    private function callMcp(bool $asPost): Response
    {
        if ($asPost) {
            $_SERVER['REQUEST_METHOD'] = 'POST';
        }
        Craft::$app->set('response', new Response());

        // No Authorization header → unauthenticated.
        Craft::$app->getRequest()->getHeaders()->remove('Authorization');

        $controller = new McpController('mcp', Plugin::getInstance());

        return $controller->actionIndex();
    }
}
