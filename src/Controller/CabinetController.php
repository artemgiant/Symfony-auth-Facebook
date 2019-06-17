<?php

namespace App\Controller;

use App\Entity\Order;
use App\Entity\User;
use App\Entity\Address;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

use App\Form\AddressFormType;
use App\Service\LiqPayService;
use Symfony\Contracts\Translation\TranslatorInterface;


class CabinetController extends AbstractController
{

    public $user;
    public $my_address;

    public $optionToTemplate;

    public function getTemplateData()
    {
        $this->user = $this->getUser();
        $this->my_address = $this->getMyAddress($this->user->getId());
        $this->optionToTemplate=[
            'user'=>$this->user,
            'my_address' => $this->my_address
        ];

    }

    public function getMyAddress($user_id)
    {
        return $this->getDoctrine()
            ->getRepository(User::class)
            ->getMyAddress($user_id);
    }
}

