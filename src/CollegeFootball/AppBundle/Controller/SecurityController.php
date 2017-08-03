<?php

namespace CollegeFootball\AppBundle\Controller;

use Swift_Mailer;
use Symfony\Component\Form\Extension\Core\Type as SymfonyTypes;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\SecurityContextInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Http\Event\InteractiveLoginEvent;

use CollegeFootball\AppBundle\Entity\Person;
use CollegeFootball\AppBundle\Form\Type\PersonType;

/**
 * Security Controller
 */
class SecurityController extends Controller
{
    /**
     * @Route("/login", name="collegefootball_security_login")
     */
    public function loginAction(Request $request)
    {
        $authenticationUtils = $this->get('security.authentication_utils');

        // get the login error if there is one
        $error = $authenticationUtils->getLastAuthenticationError();

        // last username entered by the user
        $lastUsername = $authenticationUtils->getLastUsername();

        return $this->render('CollegeFootballAppBundle:Security:login.html.twig', [
            'username' => $lastUsername,
            'error'    => $error,
        ]);
    }

    /**
     * @Route("/create-account", name="collegefootball_security_create")
     */
    public function createAction(Request $request)
    {
        $person = new Person();

        $username = substr(str_shuffle(str_repeat($x='0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ', ceil(10/strlen($x)) )),1,10);
        $person->setUsername($username);

        $form = $this->createForm(PersonType::class, $person, [
            'create' => true,
        ]);

        $form->handleRequest($request);

        if ($form->isValid()) {
            $passwordEncoder = $this->get('security.password_encoder');
            $password        = $passwordEncoder->encodePassword($person, $person->getPassword());
            $person->setPassword($password);

            $username = $person->getEmail();
            $username = explode('@', $username);
            $person->setUsername($username[0]);

            $em = $this->getDoctrine()->getManager();
            $em->persist($person);
            $em->flush();

            $token = new UsernamePasswordToken($person, null, "secured_area", $person->getRoles());
            $this->get("security.token_storage")->setToken($token); //now the user is logged in

            //now dispatch the login event
            $event   = new InteractiveLoginEvent($request, $token);
            $this->get("event_dispatcher")->dispatch("security.interactive_login", $event);

            return $this->redirectToRoute('collegefootball_app_index');
        }

        return $this->render('CollegeFootballAppBundle:Security:create.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/forgot", name="collegefootball_security_forgot")
     */
    public function forgotAction(Request $request)
    {
        $form = $this->createFormBuilder()
            ->add('email', SymfonyTypes\EmailType::class)
            ->getForm();

        $form->handleRequest($request);

        if ($form->isValid()) {
            $email = $form->getData()['email'];

            $em         = $this->getDoctrine()->getManager();
            $repository = $em->getRepository('CollegeFootballAppBundle:Person');
            $person     = $repository->findOneByEmail($email);

            if (count($person)) {
                // set temp password and append to email link
                $factory        = $this->get('security.encoder_factory');
                $encoder        = $factory->getEncoder($person);
                $hash           = time();
                $hash           = md5($hash);
                $hash           = substr(str_shuffle($hash), 0, 8);
                $uniquePassword = 'CollegeFootball-'.$hash;
                $newPassword    = md5($uniquePassword);
                $person->setTempPassword($newPassword);

                $em->flush();

                // send email
                $message = \Swift_Message::newInstance()
                    ->setSubject('CollegeFootball Forgot Password')
                    ->setFrom('noreply@college-football.herokuapp.com')
                    ->setTo($person->getEmail())
                    ->setBody(
                        $this->render('CollegeFootballAppBundle:Email:forgotPassword.html.twig', [
                            'unique_password' => $uniquePassword,
                        ]),
                        'text/html'
                    );

                $this->get('mailer')->send($message);

                $this->addFlash('info', 'Your temporary password has been assigned. Please check your email ('.$person->getEmail().') for your temporary password.');

                return $this->redirectToRoute('collegefootball_security_login');
            }

            $this->addFlash('error', 'No user found with email "'.$email.'"');
        }

        return $this->render('CollegeFootballAppBundle:Security:forgotPassword.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/forgot-password/{tempPass}", name="collegefootball_security_forgot_password")
     */
    public function forgotPasswordAction($tempPass, Request $request)
    {
        $em         = $this->getDoctrine()->getManager();
        $repository = $em->getRepository('CollegeFootballAppBundle:Person');
        $person     = $repository->findOneByTempPassword(md5($tempPass));

        if (count($person) > 0) {
            // login the user with their roles
            $token = new UsernamePasswordToken($person, null, 'secured_area', $person->getRoles());
            $this->get('security.token_storage')->setToken($token);

            // now dispatch the login event
            $event   = new InteractiveLoginEvent($request, $token);
            $this->get("event_dispatcher")->dispatch("security.interactive_login", $event);

            // store flash message
            $this->addFlash('info', 'Please update your password.');
        }

        return $this->redirectToRoute('collegefootball_person_show', [
            'username' => $person->getUsername(),
        ]);
    }
}
