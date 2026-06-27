<?php

namespace fabianhaef\simpleform\controllers;

use Craft;
use craft\web\Controller;
use fabianhaef\simpleform\elements\Form;
use fabianhaef\simpleform\Plugin;
use fabianhaef\simpleform\web\assets\form\FormAsset;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Front-end distribution for a form (#247): a hosted standalone page that shows
 * just the form (the shareable URL, and the iframe target for the embed modes),
 * plus the small embed loader script the copy-paste snippets reference.
 *
 * Both actions are anonymous reads — the form itself still posts through the
 * unchanged {@see SubmitController} pipeline (CSRF, spam, validation intact).
 *
 * @author Fabian Haefliger
 * @since 1.0.0
 */
class FormController extends Controller
{
    /** Public, anonymous reads: the standalone page and the embed loader. */
    protected array|bool|int $allowAnonymous = self::ALLOW_ANONYMOUS_LIVE;

    /**
     * Render a single form as a full standalone HTML page on the current site —
     * the shareable URL and the iframe source for the embed modes. 404s for an
     * unknown handle. Rendering the form first registers its asset bundle so the
     * page's `head()`/`endBody()` emit the form CSS/JS.
     */
    public function actionStandalone(string $handle): Response
    {
        $form = Form::find()
            ->handle($handle)
            ->siteId(Craft::$app->getSites()->getCurrentSite()->id)
            ->one();

        if (!$form instanceof Form) {
            throw new NotFoundHttpException('Form not found');
        }

        $html = Plugin::getInstance()->getFormRender()->renderForm($handle);

        return $this->renderTemplate('simple-form/standalone', [
            'form' => $form,
            'formHtml' => $html,
        ]);
    }

    /**
     * Serve the embed loader script the copy-paste snippets reference. It finds
     * `[data-sf-embed]` elements and renders the form (from its standalone URL)
     * inline, in a modal, or in a slide-in panel. Served from a stable URL so the
     * snippet is portable; cached for an hour.
     */
    public function actionEmbedScript(): Response
    {
        $js = @file_get_contents(FormAsset::distPath('js/embed.js'));

        $response = $this->response;
        $response->format = Response::FORMAT_RAW;
        $response->getHeaders()
            ->set('Content-Type', 'application/javascript; charset=UTF-8')
            ->set('Cache-Control', 'public, max-age=3600');
        $response->content = $js !== false ? $js : '';

        return $response;
    }
}
