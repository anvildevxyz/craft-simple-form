<?php

namespace fabianhaef\simpleform\helpers;

use Craft;
use craft\models\Site;
use craft\web\Request;
use yii\web\NotFoundHttpException;

class SiteHelper
{
    /** Resolve the site from the ?site= query param, falling back to the current site. */
    public static function getSiteForRequest(Request $request, bool $requireSiteParam = false): Site
    {
        $siteHandle = $request->getQueryParam('site');
        if (!$siteHandle) {
            if ($requireSiteParam) {
                throw new NotFoundHttpException('Site parameter is required but not provided');
            }

            return Craft::$app->getSites()->getCurrentSite();
        }

        // Strip any appended query parameters from the handle
        $cleanHandle = strtok($siteHandle, '?');

        $site = Craft::$app->getSites()->getSiteByHandle($cleanHandle);
        if (!$site) {
            throw new NotFoundHttpException('Site not found: ' . $cleanHandle);
        }

        self::applySite($site);

        return $site;
    }

    /** Resolve the site from a posted siteId form field, falling back to the current site. */
    public static function getSiteFromPost(Request $request): Site
    {
        $sites = Craft::$app->getSites();
        if (($siteId = $request->getBodyParam('siteId')) && ($site = $sites->getSiteById((int)$siteId))) {
            self::applySite($site);
            return $site;
        }

        return $sites->getCurrentSite();
    }

    /** Set the current site and application language. */
    private static function applySite(Site $site): void
    {
        Craft::$app->getSites()->setCurrentSite($site);
        Craft::$app->language = $site->language;
    }
}
