<?php

namespace AppBundle\Controller;

use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;

use AppBundle\Entity\Week;
use AppBundle\Form\Type\WeekType;

/**
 * @Route("/week")
 */
class WeekController extends Controller
{
    /**
     * @Route("/{season}", name="app_week_index", defaults={"season": null})
     */
    public function indexAction($season = null)
    {
        if (! $season) {
            $season = date('Y');
        }

        $em         = $this->getDoctrine()->getManager();
        $repository = $em->getRepository('AppBundle:Week');
        $weeks      = $repository->findBySeason($season);

        return $this->render('AppBundle:Week:index.html.twig', [
            'weeks'  => $weeks,
            'season' => $season,
        ]);
    }

    /**
     * @Route("/{season}/add", name="app_week_add")
     * @Security("is_granted('ROLE_MANAGE')")
     */
    public function addAction($season, Request $request)
    {
        $week = new Week();
        $week->setSeason($season);

        $em         = $this->getDoctrine()->getManager();
        $repository = $em->getRepository('AppBundle:Week');
        $weeks      = $repository->findBySeason($season);

        $form = $this->createForm(WeekType::class, $week);

        $form->handleRequest($request);

        if ($form->isValid()) {
            $em->persist($week);
            $em->flush();

            $this->addFlash('success', 'Week added');
            return $this->redirectToRoute('app_week_add', [
                'season' => $season,
            ]);
        }

        return $this->render('AppBundle:Week:addEdit.html.twig', [
            'form'   => $form->createView(),
            'season' => $season,
            'weeks'  => $weeks,
        ]);
    }
}
