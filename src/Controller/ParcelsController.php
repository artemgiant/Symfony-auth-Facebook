<?php

namespace App\Controller;

use App\Controller\CabinetController;
use App\Entity\Country;
use App\Entity\Order;
use App\Entity\User;
use App\Entity\Address;
use App\Entity\Invoices;
use App\Entity\OrderStatus;
use App\Entity\OrderType;
use App\Entity\PriceWeightEconom;
use App\Entity\PriceWeightEconomVip;
use App\Entity\DeliveryPrice;
use App\Entity\OrderProducts;
use App\Form\SupportType;
use App\Service\DhlDeliveryService;
use App\Service\SkladUsaService;
use Swift_Mailer;
use Swift_SmtpTransport;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use App\Form\OrderFormType;

use Knp\Component\Pager\PaginatorInterface;
use Doctrine\Common\Collections\ArrayCollection;

use App\Service\LiqPayService;


//use Swift_Message;
/**
 * @Route("/post/parcels")
 */
class ParcelsController extends CabinetController
{

    /**
     * new orders list
     * @Route("/", name="post_parcels")
     */
    public function parcelsAction(Request $request, PaginatorInterface $paginator, LiqPayService $liqPay ): Response
    {
        $this->getTemplateData();
        $this->optionToTemplate['page_id']='post_parcels';
        $this->optionToTemplate['page_title']='New Parcels List';

        $entityManager = $this->getDoctrine()->getManager();

        $orders = $entityManager
            ->getRepository(Order::class)
            ->getNewOrders($this->user->getId());

        $ordersList = $paginator->paginate(
            $orders,
            $request->query->getInt('page', 1),
            20
        );
        $totalItemCount = $ordersList->getTotalItemCount();

        return $this->render('cabinet/parcels/parcels.html.twig'
            , array_merge($this->optionToTemplate,[
                'items'=>$ordersList,
                'totalItemCount'=>$totalItemCount,
            ])
        );
    }

    /**
     * sent orders list
     * @Route("/send", name="post_parcels_send")
     */
    public function parcelsSendAction(Request $request, PaginatorInterface $paginator): Response
    {
        $this->getTemplateData();
        $this->optionToTemplate['page_id']='post_parcels_send';
        $this->optionToTemplate['page_title']='Send Parcels List';

        $entityManager = $this->getDoctrine()->getManager();

        $orders = $entityManager
            ->getRepository(Order::class)
            ->getSendOrders($this->user->getId());

        $ordersList = $paginator->paginate(
            $orders,
            $request->query->getInt('page', 1),
            20
        );

        $totalItemCount = $ordersList->getTotalItemCount();

        return $this->render('cabinet/parcels/parcels.html.twig'
            , array_merge($this->optionToTemplate,[
                'isSend'=>1,
                'items'=>$ordersList,
                'totalItemCount'=>$totalItemCount,
            ])
        );
    }

    /**
     * @Route("/create", name="post_parcels_create")
     */
    public function parcelsCreateAction(Request $request,TranslatorInterface $translateService): Response
    {
        $this->getTemplateData();
        $errors =[];
        $this->optionToTemplate['page_id']='post_parcels_create';
        $this->optionToTemplate['page_title']='Parcels Create';

        $order = new Order();

        $orderForm=$request->request->get('order_form',false);
        if ($orderForm)
        {
            if ($products=$orderForm['products']??false){
                foreach($products as $product){
                    $orderProduct=new OrderProducts();
                    $order->addProduct($orderProduct);
                }
            }
        }
        else{
            $orderProduct=new OrderProducts();
            $order->addProduct($orderProduct);
        }

        $maxWeightEconom = $this->getDoctrine()
            ->getRepository(PriceWeightEconom::class)
            ->findMaxWeight();
        $maxWeightEconomVip = $this->getDoctrine()
            ->getRepository(PriceWeightEconomVip::class)
            ->findMaxWeight();

        $form = $this->createForm(OrderFormType::class, $order, ['attr'=>['user' => $this->user, 'maxWeightEconom' => $maxWeightEconom, 'maxWeightEconomVip' => $maxWeightEconomVip]]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager = $this->getDoctrine()->getManager();
            $declareValue=0;
            if ($order->getProducts()) {
                foreach ($order->getProducts() as $product) {
                    if (empty($product->getDescEn())) {
                        $order->removeProduct($product);
                        $entityManager->remove($product);
                        $entityManager->persist($order);
                    } else {
                        $product->setOrderId($order);
                        $entityManager->persist($product);
                        $declareValue=$declareValue+$product->getCount()*$product->getPrice();
                    }
                }
            }
            unset($product);
            $order->setDeclareValue($declareValue);
            list($shipCost,$volume)=$this->CalculateShipCost($order);
            $order->setShippingCosts($shipCost);
            $order->setVolumeWeigth($volume);

//Calculate shipping costs for ECONOM type . Use price-weight data.
            if($order->getOrderType()->getCode() == 'econom') {
                if($this->user->isVip()) {
                    $weightPrice = $this->getDoctrine()
                        ->getRepository(PriceWeightEconomVip::class)
                        ->findPriceByWeight((float)$orderForm['sendDetailWeight']);
                    if ($weightPrice) {
                        $order->setShippingCosts($weightPrice->getPrice());
                    } else {
                        $order->setShippingCosts(null);
                    }
                } else {
                    $weightPrice = $this->getDoctrine()
                        ->getRepository(PriceWeightEconom::class)
                        ->findPriceByWeight((float)$orderForm['sendDetailWeight']);
                    if ($weightPrice) {
                        $order->setShippingCosts($weightPrice->getPrice());
                    } else {
                        $order->setShippingCosts(null);
                    }
                }
            }

//Calculate shipping costs for EXPRESS type . Use Dhl service .
            if($order->getOrderType()->getCode() == 'express') {
                $order->setUser($this->user);
                $Country_r = $this->getDoctrine()->getRepository(Country::class);
                list($From,$To) = $Country_r->getShortNameCountry($this->my_address['country'],$order->getAddresses()->getCountry()->getId());
                $One_order = $order;
                $dhlSendBoxAddress =$this->my_address;
                $dhlSendBoxAddress['from']=$From;
                $dhlSendBoxAddress['to']=$To;
                $Dlh = new DhlDeliveryService($dhlSendBoxAddress);
//                dd( $Dlh->getAccountId($One_order));
                $FinalPrice = $Dlh->getDHLPrice($One_order);
                if(!$FinalPrice){
                    $this->addFlash('errors','Вы превысили допустимое значения!');
                    return $this->redirectToRoute('post_parcels_create');
                }
                $order->setShippingCosts($FinalPrice);
            }

            $invoice=new Invoices();
            $invoice->setOrderId($order)
                ->setPrice($order->getShippingCosts());
            $order->setUser($this->user);
            $orderStatus=$entityManager->getRepository(OrderStatus::class)->findOneBy(['status'=>'new']);
            $order->setOrderStatus($orderStatus);
            $entityManager->persist($invoice);
            $order->addInvoice($invoice);
            $entityManager->persist($order);
            $entityManager->flush();
            $this->addFlash(
                'success',
                $translateService->trans("Orders Added sucusfull")
            );
            return $this->redirectToRoute('post_parcels');
        }elseif ($form->isSubmitted() && !$form->isValid()){
            $errors = $form->getErrors(true);
        }
        $twigoption=array_merge($this->optionToTemplate,['form' => $form->createView(),
            'error' => $errors,]);
        return $this->render('cabinet/parcels/editform.html.twig', $twigoption);

    }

    /**
     * @Route("/{id}/edit", name="post_parcels_edit")
     */
    public function parcelsEditAction(Request $request,TranslatorInterface $translateService): Response
    {
        $this->getTemplateData();
        $entityManager = $this->getDoctrine()->getManager();
        $errors =[];
        $this->optionToTemplate['page_id']='post_parcels';
        $this->optionToTemplate['page_title']='Order Edit';
        $id = $request->get('id',false);
        if ($id && (int)$id>0){
            $order =$entityManager->getRepository(Order::class)->find((int)$id);
            if(empty($order) || $order->getUser()!=$this->getUser()){
                throw new ServiceException('Not found');
            }

        }
        /* @var $order Order */
        $originalProducts = new ArrayCollection();

        // Создать ArrayCollection текущих объектов Tag в БД
        foreach ($order->getProducts() as $product) {
            $originalProducts->add($product);
        }


        $originalCount=$originalProducts->count();
        $orderForm=$request->request->get('order_form',false);
        if ($orderForm)
        {
            if ($products=$orderForm['products']??false){

                $count=count($products) - $originalCount;

                if ($count>0){
                    for ($x=0; $x<=$count; $x++){
                        $orderProduct=new OrderProducts();
                        $order->addProduct($orderProduct);
                    }
                }

            }
        }
        //$address = new Address();

        $maxWeightEconom = $this->getDoctrine()
            ->getRepository(PriceWeightEconom::class)
            ->findMaxWeight();
        $maxWeightEconomVip = $this->getDoctrine()
            ->getRepository(PriceWeightEconomVip::class)
            ->findMaxWeight();

        $form = $this->createForm(OrderFormType::class, $order, ['attr'=>['user' => $this->user, 'maxWeightEconom' => $maxWeightEconom, 'maxWeightEconomVip' => $maxWeightEconomVip]]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $declareValue=0;
            if ($order->getProducts()){
                foreach ($order->getProducts() as $product){

                    if (empty($product->getDescEn())) {
                        $order->removeProduct($product);
                        $entityManager->remove($product);
                        $entityManager->persist($order);
                    } else {
                        $product->setOrderId($order);
                        $entityManager->persist($product);
                        $declareValue=$declareValue+$product->getCount()*$product->getPrice();
                    }
                }
            }

            unset($product);
            $order->setDeclareValue($declareValue);
            list($shipCost,$volume)=$this->CalculateShipCost($order);
            $order->setShippingCosts($shipCost);
            $order->setVolumeWeigth($volume);

//Calculate shipping costs for ECONOM type . Use price-weight data.
            if($order->getOrderType()->getCode() == 'econom') {
                if($this->user->isVip()) {
                    $weightPrice = $this->getDoctrine()
                        ->getRepository(PriceWeightEconomVip::class)
                        ->findPriceByWeight((float)$orderForm['sendDetailWeight']);
                    if ($weightPrice) {
                        $order->setShippingCosts($weightPrice->getPrice());
                    } else {
                        $order->setShippingCosts(null);
                    }
                } else {
                    $weightPrice = $this->getDoctrine()
                        ->getRepository(PriceWeightEconom::class)
                        ->findPriceByWeight((float)$orderForm['sendDetailWeight']);
                    if ($weightPrice) {
                        $order->setShippingCosts($weightPrice->getPrice());
                    } else {
                        $order->setShippingCosts(null);
                    }
                }
            }

//Calculate shipping costs for EXPRESS type . Use Dhl service .
            if($order->getOrderType()->getCode() == 'express') {
                $order->setUser($this->user);
                $Country_r = $this->getDoctrine()->getRepository(Country::class);
                list($From,$To) = $Country_r->getShortNameCountry($this->my_address['country'],$order->getAddresses()->getCountry()->getId());
                $One_order = $order;
                $dhlSendBoxAddress =$this->my_address;
                $dhlSendBoxAddress['from']=$From;
                $dhlSendBoxAddress['to']=$To;
                $Dlh = new DhlDeliveryService($dhlSendBoxAddress);
//                dd( $Dlh->getAccountId($One_order));
                $FinalPrice = $Dlh->getDHLPrice($One_order);
                if(!$FinalPrice){
                    $this->addFlash('errors','Вы превысили допустимое значения!');
                    return $this->redirectToRoute('post_parcels_create');
                }
                $order->setShippingCosts($FinalPrice);
            }

            foreach ($originalProducts as $product) {
                if (false === $order->getProducts()->contains($product)) {

                    $entityManager->remove($product);
                }
            }
            $noInvoice=true;
            if (!empty($order->getInvoices())){
                foreach($order->getInvoices() as $invoice){
                    /* @var Invoices $invoice*/
                    if (!$invoice->isPaid()){
                        $noInvoice=false;
                        if ($invoice->getPrice()<>$order->getShippingCosts() ){
                            $invoice->setPrice($order->getShippingCosts());
                            $entityManager->persist($invoice);
                        }
                    }
                }
            }
            if ($noInvoice){
                $invoice=new Invoices();
                $invoice->setOrderId($order)
                    ->setPrice($order->getShippingCosts());
                $entityManager->persist($invoice);
                $order->addInvoice($invoice);
            }

            $entityManager->persist($order);
            $entityManager->flush();
            $this->addFlash(
                'success',
                $translateService->trans("Orders Update sucusfull")
            );
            return $this->redirectToRoute('post_parcels');
        }elseif ($form->isSubmitted() && !$form->isValid()){
            $errors = $form->getErrors(true);
        }
        $twigoption=array_merge($this->optionToTemplate,['form' => $form->createView(),
            'error' => $errors,]);

        return $this->render('cabinet/parcels/editform.html.twig', $twigoption);

    }

    private function CalculateShipCost( $object )
    {
        $entityManager = $this->getDoctrine()->getManager();
        $resReturn=0;
        $volume=0;
        /* @var $object Order */
        $weight=(float)$object->getSendDetailWeight();
        $s1=(float)$object->getSendDetailWidth();
        $s2=(float)$object->getSendDetailHeight();
        $s3=(float)$object->getSendDetailLength();
        $volume=round($s1*$s2*$s3/5000,3);
        $resW=max($weight,$volume);
        if (!empty($resW)){
            $a=floor($resW);
            $b = $resW - $a;

            if ($b!=0){
                if ($b<=0.25){
                    $b=0.25;
                }else if ($b<=0.500){
                    $b=0.5;
                }else if ($b<=0.75){
                    $b=0.75;
                }else{
                    $b=0;
                    $a=$a+1;
                }
            }

            $resReturn=0;
            $WeightPrice = $entityManager->getRepository(DeliveryPrice::class)->findAll();
            foreach($WeightPrice as $weight){
                /* @var $weight DeliveryPrice */
                if ($b==0.25 && $weight->getWeight()==0.25) $resReturn=$resReturn+$weight->getCost();
                if ($b==0.5 && $weight->getWeight()==0.5) $resReturn=$resReturn+$weight->getCost();
                if ($b==0.75 && $weight->getWeight()==0.75) $resReturn=$resReturn+$weight->getCost();
                if ($a>0 && $weight->getWeight()==1) $resReturn=$resReturn+$a*$weight->getCost();
            }

        }

        return [$resReturn,$volume];
    }

    /**
     * @Route("post/parcels/supprort",name="parcles_support")
     */
    public function parclesSupport(Request $request,\Swift_Mailer $mailer)
    {
        $this->getTemplateData();
        $form = $this->createForm(SupportType::class, null, ['attr' => ['user' => $this->user]]);
        $form->handleRequest($request);
        $errors = [];


        if ($form->isSubmitted()  && $form->isValid()) {
            $user= $this->user;
            $template = $this->render('cabinet/support/SupportFormMessage.html.twig');
            $message = (new \Swift_Message('Hello Email'))
                ->setFrom('send@example.com')
                ->setTo('recipient@example.com')
                ->setBody(html_entity_decode($template),'text/html');


            $mailer->send($message);

            $this->addFlash('modal_window',"true");

            return $this->redirectToRoute('parcles_support');
        }

        if ($form->isSubmitted() && !$form->isValid()) {
            $errors = $form->getErrors(true);

        }
        $twigoption = array_merge($this->optionToTemplate, ['SupportForm' => $form->createView(),
            'error' => $errors]);
        return $this->render('cabinet/support/support_form.html.twig', $twigoption);
    }

//    /**
//     * @Route("/{id}/sendtosklad", name="post_sendtosklad")
//     */
//    public function parclesSendToSklad(Request $request)
//    {
//        $entityManager = $this->getDoctrine()->getManager();
//        $id = $request->get('id',false);
//        if ($id && (int)$id>0) {
//            $order = $entityManager->getRepository(Order::class)->find((int)$id);
//            if (empty($order) || $order->getUser() != $this->getUser()) {
//               // throw new ServiceException('Not found');
//            }
//        }
//
//        if(!empty($order->getOrderStatus())&&($order->getOrderStatus()->getStatus() == 'paid')){
//            $service = new SkladUsaService();
//            $result = $service->sendOrderToSklad($order);
//            if(json_decode($result)->status == 'success') {
//                $orderStatus = $entityManager->getRepository(OrderStatus::class)->findOneBy(['status' => 'complit']);
//                $order->setOrderStatus($orderStatus);
//                $entityManager->persist($order);
//                $entityManager->flush();
//            }
//        }
//
//        $referer = $request->headers->get('referer');
//        return $this->redirect($referer);
//    }

}

