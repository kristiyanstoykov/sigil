<?php

declare(strict_types=1);

namespace App\Account\Controller;

use App\Account\Form\RequestDataExportForm;
use App\Account\Repository\DataExportRepository;
use App\Account\Service\DataExportRequester;
use App\Core\Exception\DomainException;
use App\Core\Security\CurrentUser;
use App\TempDownload\Form\TakeDownloadFormFactory;
use App\TempDownload\Repository\TempDownloadRepository;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/** "Download all my documents": available at any time, not only on deletion (ADR-015 §7). */
#[IsGranted('IS_AUTHENTICATED_FULLY')]
#[Route('/account/export')]
class DataExportController extends AbstractController
{
    public function __construct(
        private readonly CurrentUser $currentUser,
        private readonly DataExportRepository $exports,
    ) {
    }

    #[Route('', name: 'app_account_export', methods: ['GET'])]
    public function show(TempDownloadRepository $downloads, TakeDownloadFormFactory $forms, ClockInterface $clock): Response
    {
        $user = $this->currentUser->get();
        $export = $this->exports->findLatestFor($user);
        $now = $clock->now();

        $files = [];
        if (null !== $export) {
            foreach ($downloads->findGroup($user, $export->getId()) as $download) {
                $files[] = [
                    'download' => $download,
                    'available' => $download->isAvailable($now),
                    'form' => $download->isAvailable($now) ? $forms->create($download)->createView() : null,
                ];
            }
        }

        return $this->render('account/export.html.twig', [
            'export' => $export,
            'files' => $files,
            'request_form' => $this->createForm(RequestDataExportForm::class, options: [
                'action' => $this->generateUrl('app_account_export_request'),
            ])->createView(),
        ]);
    }

    #[Route('', name: 'app_account_export_request', methods: ['POST'])]
    public function request(
        Request $request,
        DataExportRequester $requester,
        #[Autowire(service: 'limiter.data_export')]
        RateLimiterFactory $exportLimiter,
    ): Response {
        $user = $this->currentUser->get();
        $form = $this->createForm(RequestDataExportForm::class);
        $form->handleRequest($request);

        if (!$form->isSubmitted() || !$form->isValid()) {
            $this->addFlash('danger', 'Your session expired. Please try again.');
        } elseif (!$exportLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
            $this->addFlash('danger', 'You have requested several exports today. Please try again tomorrow.');
        } else {
            try {
                $requester->request($user);
                $this->addFlash('success', 'Your export is being prepared. We will let you know when it is ready.');
            } catch (DomainException $e) {
                $this->addFlash('warning', $e->getMessage());
            }
        }

        return $this->redirectToRoute('app_account_export');
    }
}
