<?php

namespace App\Controller;

use App\Entity\Order;
use App\Form\AddressSenderFormType;
use App\Form\ProfileFormType;
use App\Helper\ConverterArrayToObject;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;

use App\Entity\User;
use App\Entity\Address;
use App\Form\AddressFormType;
/**
 * @Route("/post/profile")
 */
class ProfileController extends CabinetController
{
    public $user;

    public function __construct()
    {}

    /**
     * @Route("/", name="post_profile")
     * @param Request $request
     * @return Response
     */
    public function profileAction(Request $request): Response
    {
        $this->user = $this->getUser();
        $form = $this->createForm(ProfileFormType::class, $this->user);
        $form->handleRequest($request);

        $errors =[];
        $mess='';
        if ($form->isSubmitted() && $form->isValid())
        {
            /**
             * @var UploadedFile $file
             */
            $file = $form->get('avatarFile')->getData();
            if($file)
            {
                $fileName = $this->generateUniqueFileName().'.'.$file->guessExtension();
                $file->move($this->getParameter('avatar_directory'), $fileName);
                //kostil
                $this->user->setAvatar('sklad-express/uploads/avatars/'.$fileName);
            }

            $entityManager = $this->getDoctrine()->getManager();
            $entityManager->persist($this->user);
            $entityManager->flush();
//            $mess='Save success';
            $mess='Изменения успешно сохранены';
        }
        elseif($form->isSubmitted() && !$form->isValid())
        {
            $errors=(string)$form->getErrors(true);
        }

        return $this->render('cabinet/profile/profile.html.twig', [
            'user' => $this->user,
            'profileForm' => $form->createView(),
            'errors'=>$errors,
            'mess'=>$mess,
            'my_address' => $this->getMyAddress($this->user->getId()),
            'page_id'=>'post_profile'
        ]);
    }

    /**
     * @Route("/from-address", name="post_profile_from_address")
     */
    public function fromAddressAction(Request $request): Response
    {
        $this->getTemplateData();
        $entityManager = $this->getDoctrine()->getManager();
        $errors =[];
        $this->optionToTemplate['page_id']='post_profile_from_address';
        $this->optionToTemplate['page_title']='post_profile_from_address';
        $this->optionToTemplate['my_address'];
       if (!empty($this->optionToTemplate['my_address']) && $this->optionToTemplate['my_address'] !== ' ')
       {
           $address = $this->getDoctrine()
               ->getRepository(Address::class)
               ->find($this->optionToTemplate['my_address']['id']);
       }
       else
       {
           $address = new Address();
       }

        $form = $this->createForm(AddressSenderFormType::class, $address);
        $form->handleRequest($request);
        if ($form->isSubmitted() && $form->isValid())
        {
            $address->setUser($this->user);
            $address->setIsMyAddress(1);
            $address->setAddress();
            $entityManager->persist($address);
            $entityManager->flush();

            // do anything else you need here, like send an email

            return $this->redirectToRoute('post_profile_from_address');
        }
        elseif ($form->isSubmitted() && !$form->isValid())
        {
            dd(!true);
            $errors = $form->getErrors(true);
        }

        $twigoption=array_merge($this->optionToTemplate,[
            'addressForm' => $form->createView(),
            'error' => $errors,
        ]);

        return $this->render('cabinet/profile/edit_form.html.twig', $twigoption);
    }


    /**
    * @return string
    */
    private function generateUniqueFileName()
    {
        return md5(uniqid());
    }
}

