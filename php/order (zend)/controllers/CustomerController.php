<?php
/**
 * Class Order_Customer Controller
 * It is used to print customer tab content, get customer contact details and add new order
 */
class Order_CustomerController extends BAS_Shared_Controller_Action_Abstract
{
    /**
     * Get affiliated depot masters with active depot
     * 
     * @var array  
     */
    protected $_affiliatedDepotMastersWithActiveDepot;
    
    /**
     * Get active depot id
     *
     * @var integer
     */
    protected $_activeDepotId;
    
    public function init() 
    {
        parent::init();
        $this->_affiliatedDepotMastersWithActiveDepot = $this->getHelper('depot')->getAffiliatedDepotMastersWithActiveDepot();
        $this->_activeDepotId = $this->getLoggedInDepotId();
    }
    
    /**
     * Customer tab view
     */
    public function indexAction()
    {
        $user = $this->getHelper('Auth')->getIdentity();
        $config = $this->getInvokeArg('bootstrap')->getResource('config');
        $orderId = $this->getRequest()->getParam('id');
        $vehicleId = $this->getRequest()->getParam('vehicleId');
        $data = $this->getRequest()->getParams();
        $access = false;
        $contactService = new Contacts_Service_Contact();
        $contactService->setConfig($config->toArray());
        $orderService = $this->_getOrderService($config->toArray());
        $vehicleService = new Vehicles_Service_Vehicle();

        if (0 < (int)$orderId) {
            $orderData = $orderService->find($orderId);
            $access = $orderService->checkDepotRightForOrder($orderId, $orderData->depotId);
        }
        
        if ($access || 0  === (int)$orderId) {
            $redirect = false;
            if (0 < (int)$orderId) {
                $orderModel = $orderService->findById($orderId);
            } else {
                $depotId = (int)$this->getParam('depotId', $this->_activeDepotId);
                $options['contactId'] = (int)$this->getParam('contactId', null);
                $options['personId'] = (int)$this->getParam('personId', null);
                $options['requestTicketId'] = (int)$this->getParam('ticketId', null);
                $orderModel = $orderService->add($depotId,
                    $this->getUserInfo()->getId(),
                    (int)$this->getParam('type', BAS_Shared_Model_Order::TYPE_VEHICLE),
                    $options);
                $orderId = $orderModel->id;
                $redirect = true;
            }
            
            if (!isset($orderData)) {
                $orderData = $orderService->find($orderId);
            }
            
            if (isset($vehicleId)) {
                $rentOrder = false;
                $errorForAddVehicleIds = [];

                if (BAS_Shared_Model_Order::TYPE_RENT_ORDER === $orderData->type) {
                    $rentOrder = true;
                }
                $orderVehicleIds = [];
                if ([] !== $orderData->getOrderVehicles()) {
                    foreach ($orderData->getOrderVehicles() as $orderVehicleValue) {
                        $orderVehicleIds[] = $orderVehicleValue->legacyVoertuigId;
                    }
                }

                $vehicleIdArray = explode(',', $vehicleId);
                foreach ($vehicleIdArray as $vehicleId) {
                    $vehicleAvailable = true;
                    if (!in_array($vehicleId, $orderVehicleIds)) {
                        if ($rentOrder) {
                            $vehicleAvailable = $vehicleService->findByVehicleIdForOrder($vehicleId, true);
                        }

                        if (!$vehicleAvailable) {
                            $errorForAddVehicleIds[] = $vehicleId;
                            continue;
                        }

                        $orderService->addVehicle($orderModel, $vehicleId, $user);
                    }
                }
            }

            if (true === $redirect) {
                return $this->_helper->redirector('index', 'customer', 'order', ['id' => $orderModel->id]);
            }
            
            getSessionManager()->globalVar('createdAt', $orderData->createdAt);
            $this->view->assign('orderId', $orderId);
            $this->view->assign('user', BAS_Shared_Auth_Service::getAuthModelDecoratedObject($user));
            $this->view->assign('affiliatedDepotMasters', $this->_affiliatedDepotMastersWithActiveDepot);
            $this->view->assign($this->_getCustomerOrderPageUrlPathDetails($config));
            $this->view->assign($contactService->getOrderContactDetails($orderData, $this->_activeDepotId, $data));
            $this->view->assign($orderService->getOrderVehicleDetailsForView($orderData, $data));
            $this->view->assign($orderService->getCustomerOrderPageDetails($orderData, $this->_activeDepotId, $data));

            $viewValue = $this->getRequest()->getParam('onlyView');
            if (1 == $viewValue) {
               $this->_helper->layout()->disableLayout();
               $this->_helper->viewRenderer->setRender('customer/index', null, true);
            } else {
                $data = !empty($errorForAddVehicleIds) ? implode(', ', $errorForAddVehicleIds) : '';
                $this->view->assign('errorForAddVehicleIds', $data);
                $this->_helper->viewRenderer->setRender('order/index', null, true);
            }
        } else {
            return $this->_helper->redirector('no-access', 'index', 'default');
        }
        $this->appendJsEntryPoint();
        $this->view->activeDepotId = $this->_activeDepotId;
        $this->view->googleApiConfig = $this->getConfig()->googleApi;
    }

    /**
     * Getting order service with orderVehicle service and orderVehicle Service object.
     *
     * @param Zend_Config $config
     * @return Order_Service_Order
     */
    protected function _getOrderService(Zend_Config $config): Order_Service_Order
    {
        $order = new Order_Service_Order($config);
        $order->addService('OrderVehicle', $this->_getOrderVehicleService());
        $order->addService('OrderVehicleProduct', $this->_getOrderVehicleProductService());

        return $order;
    }

    /**
     * Getting Order vehicle service with contact type supplier fee
     *
     * @return Order_Service_OrderVehicle
     */
    protected function _getOrderVehicleService(): Order_Service_OrderVehicle
    {
        $orderVehicle = new Order_Service_OrderVehicle();
        $orderVehicle->addService('ContactTypeSupplierFee', new Contacts_Service_ContactTypeSupplierFee());

        return $orderVehicle;
    }

    /**
     * Getting order vehicle product service object with incoterm and vehicleBuilding service object.
     *
     * @return Order_Service_OrderVehicleProduct
     */
    protected function _getOrderVehicleProductService(): Order_Service_OrderVehicleProduct
    {
        $orderVehicleProduct = new Order_Service_OrderVehicleProduct();
        $orderVehicleProduct->addService('Incoterm', new Order_Service_Incoterm());
        $orderVehicleProduct->addService('VehicleBuilding', new Vehicles_Service_VehicleBuilding());

        return $orderVehicleProduct;

    }
   
    /**
     * To display customer contact details
     */
    public function contactdetailsAction()
    {
        $config =  $this->getInvokeArg('bootstrap')->getResource('registry')->get('config');
        $contactId = $this->getRequest()->getParam('id');
        
        /** @var Contacts_Service_Contact $contactService */
        $contactService = new Contacts_Service_Contact();
        /** @var Contacts_Service_Address $contactAddressService */
        $contactAddressService = new Contacts_Service_Address();
        /** @var Order_Service_Country $countryService */
        $countryService = new Order_Service_Country();
        /** @var Contacts_Service_CompanyIndustry $companyIndustryService */
        $companyIndustryService = new Contacts_Service_CompanyIndustry();
        
        $this->view->affiliatedDepotMasters = $this->_affiliatedDepotMastersWithActiveDepot;

        if ($contactId > 0) {
            $contactData = $contactService->find($contactId);
            $this->view->contactData = $contactData;
            $this->view->blacklistedData = $contactService->getBlacklistedData($contactId);
            $this->view->addressData = $contactAddressService->findByContactId($contactId, 200, 0);
            //Tab 1 multiple contact selection changes
            $contactPersonList = $contactService->findContactPersonList($contactId);
            $this->view->arrayContactPersonData = $contactService->getContactList($contactPersonList);
            $this->view->contactPersonList = $contactPersonList;
            $this->view->contactPersonData = $contactData->people;
            $this->view->contactCompanyIndustry = $companyIndustryService->getCompanyIndustryName($contactId);
            $countryCode = isset($this->view->addressData[0]) ? $this->view->addressData[0]->getCountryCode() : '';
            $this->view->customerCompanyCountry = $countryService->find($countryCode);
            $this->view->supplier = !empty($this->getRequest()->getParam('supplier')) ? 'supplier' : '';
        } else {
            $this->view->blacklistedData = array();
            $this->view->addressData = array();
        }

        if ($this->view->isAllowed('order.customer.sales-region')) {
            $depotSettingService = new Management_Service_DepotSetting();
            $this->view->defaultSalesRegion = $depotSettingService->getDefaultSalesRegion(BAS_Shared_Auth_Service::getActiveDepotId());
        }

        $this->view->companyIndustryData = $companyIndustryService->getCompanyIndustries();
    }

    /**
     * Customer contact summary
     */
    public function contactsummaryAction()
    {
        $params = $this->getRequest()->getParams();
        /** @var Contacts_Service_Contact $contactService */
        $contactService = new Contacts_Service_Contact();
        /** @var Contacts_Service_Address $contactAddressService */
        $contactAddressService = new Contacts_Service_Address();
        /** @var Order_Service_Country $countryService */
        $countryService = new Order_Service_Country();

        $addressData = $contactAddressService->findByContactId($params['id']); 
        
        if (!empty($addressData)) {
           foreach ($addressData as $key => $data ) {
               
               if (!isset($addressData[$key]->company))
                   $addressData[$key]->company = $contactService->find($params['id'])->name; 
                   $addressData[$key]->countryCode = $countryService->find($addressData[$key]->countryCode)->name;
            }  
        }
        $this->view->invoiceContacts = $addressData;
        $this->view->vehicleId = $params['vehicleId'];
        $this->view->invoiceContactId =  $addressData[0]->id;
    }

    /**
     * Display company information 
     */
    public function customerinfoAction()
    {
        $customerId = (int)$this->getRequest()->getParam('id', 0);

        if (0 === $customerId) {
            $this->view->contact = null;

            return;
        }

        /** @var Contacts_Service_Contact $contactService */
        $contactService = new Contacts_Service_Contact();
        /** @var Contacts_Service_Address $contactAddService */
        $contactAddService = new Contacts_Service_Address();
        /** @var Contacts_Service_CompanyIndustry $companyIndustryService */
        $companyIndustryService = new Contacts_Service_CompanyIndustry();
        /** @var Order_Service_Country $countryService */
        $countryService = new Order_Service_Country();

        $contactType = $this->getRequest()->getParam('contactType');
        if (BAS_Shared_Model_Contact::TYPE_PERSON == $contactType){
            $contactType = BAS_Shared_Model_Contact::TYPE_PERSON;
        } else {
            $contactType = BAS_Shared_Model_Contact::TYPE_COMPANY;
        }
        $contactData = $contactService->find($customerId, $contactType);
        $depotId = BAS_Shared_Auth_Service::getActiveDepotId();
        $contactAddressData = $contactAddService->findByContactIdDecorated($customerId, $depotId);

        $this->view->contact = $contactData;
        $this->view->address = $contactAddressData;
        $this->view->companyBranch = '';
        $this->view->klantNumber = $contactService->getDebtorId($contactAddressData);

        if ($this->view->contact->getCompanyIndustryId()) {
            $this->view->companyIndustry = $companyIndustryService->find((int)$this->view->contact->getCompanyIndustryId());
        }
        
        if ($countryService->find($this->view->contact->getCountryCode())) {
            $this->view->country       = $countryService->find($this->view->contact->getCountryCode());
        }
        
        if ($countryService->find($this->view->contact->languageCode)) {
            $this->view->language      = $countryService->find($this->view->contact->languageCode);
        }    
    }

    /**
     * Display company information by invoice_address_id
     */
    public function invoiceAddressInfoAction()
    {
        $contactAddressId = $this->getRequest()->getParam('contactAddressId');
        $contactId = $this->getRequest()->getParam('contactId');

        /** @var Contacts_Service_Contact $contactService */
        $contactService = new Contacts_Service_Contact();
        /** @var Contacts_Service_Address $contactAddService */
        $contactAddService = new Contacts_Service_Address();
        /** @var Contacts_Service_CompanyIndustry $companyIndustryService */
        $companyIndustryService = new Contacts_Service_CompanyIndustry();
        /** @var Order_Service_Country $countryService */
        $countryService = new Order_Service_Country();

        $this->view->contact = $contactService->find($contactId);
        $this->view->address = $contactAddService->find($contactAddressId);
        $this->view->companyBranch = '';
        
        if ($this->view->contact->getCompanyIndustryId()) {
            $this->view->companyBranch = $companyIndustryService->find((int)$this->view->contact->getCompanyIndustryId());
            $this->view->country       = $countryService->find($this->view->contact->getCountryCode());
            $this->view->language      = $countryService->find($this->view->contact->languageCode);
        }
    }

    /**
     * Get order ticket details
     */
    public function ticketDetailsAction()
    {
        $orderId = $this->getRequest()->getParam('orderId', 0);
        $contactId = $this->getRequest()->getParam('contactId', 0);
        $bootstrap = $this->getInvokeArg('bootstrap');
        $ticketService = new Tickets_Service_Ticket($bootstrap->getOptions());
        $userId = $this->getUserInfo()->getId();
        $params = array(
            'depotAndAffiliateIds' => array_keys($this->_affiliatedDepotMastersWithActiveDepot),
            'orderId' => $orderId,
            'contactId' => (int)$contactId,
            'user' => $userId,
        );
        $options = array(
            'language' => BAS_Shared_Model_Ticket::LANGUAGE_DEFAULT,
            'user' => $userId,
            'translate' => $bootstrap->getResource('translate'),
            'config' => $bootstrap->getResource('config'),
        );
        $ticketGridService = new Ticket_Service_Grid($ticketService, $options);
        if (0 < (int)$contactId) {
            $this->view->ticketGridObject =  $ticketGridService->getGrid(Ticket_Service_Grid::GRID_TYPE_ORDERTICKETOVERVIEW, $params);
        }

        $this->view->ticketSources = $ticketService->getTicketSourceList(
            ['depot_id in (?)' => array_keys($this->_affiliatedDepotMastersWithActiveDepot)]
        );
    }
    
    /**
     * Save ticket as closed ticket from order page
     */
    public function addTicketAction()
    {
        $params = $this->getRequest()->getParams();
        $userId = $this->getUserInfo()->getId();
        $params['salesOrderId'] = $params['orderId'];
        $subject = $this->view->translate('automatic_ticket_for_order');
        $params['depotId'] = $this->_helper->depot->getActiveDepotId();
        $ticketService = new Tickets_Service_Ticket();
        $this->_helper->json($ticketService->createOrderTicket($params, $userId, $subject));
    }
    
    /**
     * Get customer order page url path
     *
     * @param Zend_Config $config
     * @return array
     */
    protected function _getCustomerOrderPageUrlPathDetails(Zend_Config $config): array
    {
        return [
            'extranetURL' => $config->extranet->base_url,
            'legacyURL' => $config->legacy->base_url,
            'serviceURL' => $config->api->internal_base_url,
            'vbdURL' => $config->extranet->base_url . $config->extranet->base_path,
            'imageURL' => $config->vbd->image_url,
        ];
    }
}
