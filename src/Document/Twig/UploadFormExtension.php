<?php

declare(strict_types=1);

namespace App\Document\Twig;

use App\Document\Form\UploadDocumentForm;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormView;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the upload form to the authenticated layout.
 *
 * The upload modal is included from layout/app.html.twig, so it renders on every
 * page - threading the form through every controller that renders a page would
 * be absurd. A Twig function keeps it to one place and stays lazy: the form is
 * only built where the modal is actually rendered, and only once per request.
 *
 * Deliberately NOT render(controller(...)): that is a full sub-request on every
 * page load for a form with two fields.
 */
final class UploadFormExtension extends AbstractExtension
{
    private ?FormView $view = null;

    public function __construct(private readonly FormFactoryInterface $forms)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('document_upload_form', $this->uploadForm(...)),
        ];
    }

    public function uploadForm(): FormView
    {
        return $this->view ??= $this->forms->create(UploadDocumentForm::class)->createView();
    }
}
