<?php

namespace AppBundle\Controller\Admin;

use AppBundle\Entity\Configuration;
use AppBundle\Entity\Mosque;
use AppBundle\Exception\GooglePositionException;
use AppBundle\Form\ConfigurationType;
use AppBundle\Form\MosqueType;
use AppBundle\Service\Calendar;
use AppBundle\Service\MailService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * @Route("/admin/{_locale}/mosque", requirements={"_locale"= "en|fr|ar"}, defaults={"_local"="fr"})
 */
class MosqueController extends Controller {

    /**
     * @Route("/", name="mosque_index")
     */
    public function indexAction(Request $request) {

        $search = $request->query->get("search");
        $user = $this->getUser();
        $em = $this->getDoctrine()->getManager();
        $mosqueRepository = $em->getRepository("AppBundle:Mosque");
        $qb = $mosqueRepository->search($user, $search);

        $paginator = $this->get('knp_paginator');
        $mosques = $paginator->paginate($qb, $request->query->getInt('page', 1), 10);

        $renderArray = [
            "mosques" => $mosques,
            "languages" => $this->getParameter('languages')
        ];

        return $this->render('mosque/index.html.twig', $renderArray);
    }

    /**
     * @Route("/create", name="mosque_create")
     */
    public function createAction(Request $request) {

        $mosque = new Mosque();
        $form = $this->createForm(MosqueType::class, $mosque);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $mosque = $form->getData();
            $mosque->setUser($this->getUser());
            $em = $this->getDoctrine()->getManager();
            $em->persist($mosque);
            $em->flush();
            $mailBody = $this->renderView(MailService::TEMPLATE_MOSQUE_CREATED, ['mosque' => $mosque]);
            $this->get("app.mail_service")->mosqueCreated($mosque, $mailBody);
            $this->addFlash('success', $this->get("translator")->trans("form.create.success", [
                        "%object%" => "de la mosquée", "%name%" => $mosque->getName()
            ]));

            return $this->redirectToRoute('mosque_index');
        }

        return $this->render('mosque/create.html.twig', [
                    'form' => $form->createView(),
        ]);
    }

    /**
     * @Route("/edit/{id}", name="mosque_edit")
     */
    public function editAction(Request $request, Mosque $mosque) {

        $user = $this->getUser();
        if (!$user->isAdmin() && $user !== $mosque->getUser()) {
            throw new AccessDeniedException();
        }

        $form = $this->createForm(MosqueType::class, $mosque);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid()) {
            $mosque = $form->getData();
            $em = $this->getDoctrine()->getManager();
            $em->persist($mosque);
            $em->flush();
            $this->addFlash('success', $this->get("translator")->trans("form.edit.success", [
                        "%object%" => "de la mosquée", "%name%" => $mosque->getName()
            ]));

            return $this->redirectToRoute('mosque_index');
        }
        return $this->render('mosque/edit.html.twig', [
                    'mosque' => $mosque,
                    'form' => $form->createView()
        ]);
    }

    /**
     * @Route("/delete/{id}", name="mosque_delete")
     */
    public function deleteAction(Request $request, Mosque $mosque) {
        $user = $this->getUser();
        if (!$user->isAdmin() && $user !== $mosque->getUser()) {
            throw new AccessDeniedException;
        }

        $em = $this->getDoctrine()->getManager();
        $em->remove($mosque);
        $em->flush();
        $this->addFlash('success', $this->get("translator")->trans("form.delete.success", [
                    "%object%" => "de la mosquée", "%name%" => $mosque->getName()
        ]));
        return $this->redirectToRoute('mosque_index');
    }

    /**
     * Force refresh page by updating updated_at
     * @Route("/refresh/{id}", name="message_refresh")
     */
    public function refreshAction(Request $request, Mosque $mosque) {
        $em = $this->getDoctrine()->getManager();
        $mosque->setUpdated(new \Datetime());
        $em->flush();
        return new Response();
    }

    /**
     * @Route("/configure/{id}", name="mosque_configure")
     */
    public function configureAction(Request $request, Mosque $mosque) {

        $user = $this->getUser();
        if (!$user->isAdmin() && $user !== $mosque->getUser()) {
            throw new AccessDeniedException;
        }
        $em = $this->getDoctrine()->getManager();

        $configuration = $mosque->getConfiguration();

        if (!$configuration instanceof Configuration) {
            $configuration = new Configuration();
            $configuration->setMosque($mosque);
            $em->persist($configuration);
            $em->flush();
            $em->refresh($mosque);
        }

        $form = $this->createForm(ConfigurationType::class, $configuration);

        try {
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                $configuration = $form->getData();
                $em->persist($configuration);
                $em->flush();
                return $this->redirectToRoute('mosque', [
                            'slug' => $mosque->getSlug(),
                            '_locale' => $request->getLocale(),
                ]);
            }
        } catch (GooglePositionException $exc) {
            $this->addFlash('danger', $this->get("translator")->trans("form.configure.geocode_error", [
                        "%address%" => $mosque->getLocalisation()
            ]));
        }

        $calendarDir = $this->getParameter("kernel.root_dir") . "/Resources/calendar";
        $predefinedCalendars = array_map('basename', glob($calendarDir . "/*", GLOB_ONLYDIR));
        return $this->render('mosque/configure.html.twig', [
                    'months' => Calendar::MONTHS,
                    'predefinedCalendars' => $predefinedCalendars,
                    'mosque' => $mosque,
                    'form' => $form->createView()
        ]);
    }

    /**
     * @Route("/getCsvFiles/{id}", name="mosque_csv_files")
     */
    public function getCsvFilesAction(Request $request, Mosque $mosque) {

        $zipFilePath = $this->get("app.prayer_times_service")->getFilesFromCalendar($mosque);
        if (is_file($zipFilePath)) {
            $zipFileName = $mosque->getSlug() . ".zip";
            return new BinaryFileResponse($zipFilePath, 200, ['Content-Disposition' => 'attachment; filename="' . $zipFileName . '"']);
        }

        return new Response("Cette mosquée n'as pas de calendier renseignée");
    }

    /**
     * @Route("/load-calendar", name="ajax_load_calendar")
     */
    public function loadCalendarAction(Request $request) {
        $calendarName = $request->query->get("calendarName");
        $calendarDir = $this->getParameter("kernel.root_dir") . "/Resources/calendar/$calendarName";
        $csvFiles = glob($calendarDir . "/*.csv");
        $calendar = [];

        foreach ($csvFiles as $file) {
            $calendar[] = array_map('str_getcsv', file($file));
        }

        return new JsonResponse($calendar);
    }

}
