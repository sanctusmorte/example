<?php

/**
 * Class Order_DeliveryController
 * It is used show form for website delivery
 */
class Order_DeliveryController extends BAS_Shared_Controller_Action_Abstract
{
    /**
     * Show website delivery form
     *
     * @return mixed
     * @throws Zend_Form_Exception
     */
    public function indexAction()
    {
        $params = $this->getRequest()->getParams();
        $vehicleService = new Vehicles_Service_Vehicle();
        $images = $vehicleService->getVehicleImages($params['vehicleId'] ?? 0);
        $config = $this->getConfig();
        $form =  new Order_Form_Delivery([
            'vehicleId' => $params['vehicleId'] ?? 0,
            'images' => $images,
            'imagePath' => $config->checkin->path_to_file,
            'orderId' => $params['orderId'] ?? 0,
        ]);

        $form->populate([]);

        if ($this->_request->isPost()) {
            $postData = $this->_request->getPost();
            if ($form->isValid($postData)) {
                $deliveryService = new Order_Service_Delivery();
                $deliveryService->save($postData, $this->getUserInfo()->getId());

                return $this->_helper->json(['message' => 'success']);
            }

            return $this->_helper->json(['error' => $form->getMessages()]);
        }

        $this->view->images = $images;
        $this->view->form = $form;
    }
}