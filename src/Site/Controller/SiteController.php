<?php

declare(strict_types=1);

namespace App\Site\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The public face: the home page and the legal pages. Reachable signed in or
 * not, so nothing here may read or reveal account data beyond "who is signed in".
 */
class SiteController extends AbstractController
{
    #[Route('/', name: 'app_home', methods: ['GET'])]
    public function home(): Response
    {
        return $this->render('site/home.html.twig');
    }

    #[Route('/privacy', name: 'app_privacy', methods: ['GET'])]
    public function privacy(): Response
    {
        return $this->render('site/legal.html.twig', [
            'title' => 'Privacy policy',
            'icon' => 'ti-lock',
        ]);
    }

    #[Route('/terms', name: 'app_terms', methods: ['GET'])]
    public function terms(): Response
    {
        return $this->render('site/legal.html.twig', [
            'title' => 'Terms of use',
            'icon' => 'ti-file-text',
        ]);
    }
}
