<?php

namespace App\Controller;

use App\Security\UiGuard;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * The login page of the browser UI. The form is checked by the form_login of the firewall, not here.
 */
final class LoginController extends AbstractController
{
    #[Route('/login', name: 'ui_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authentication, UiGuard $ui): Response
    {
        if (null !== $off = $ui->disabled()) {
            return $off;
        }
        if (null !== $this->getUser()) {
            return $this->redirectToRoute('ui_index');
        }

        $error = $authentication->getLastAuthenticationError();

        return $this->render('ui/login.html.twig', [
            'username' => $authentication->getLastUsername(),
            'error' => match (true) {
                null === $error => null,
                $error instanceof TooManyLoginAttemptsAuthenticationException => 'Too many attempts. Wait a minute and try again.',
                default => 'Wrong user or password.',
            },
        ]);
    }

    #[Route('/logout', name: 'ui_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('The logout key of the firewall handles this route.');
    }
}
