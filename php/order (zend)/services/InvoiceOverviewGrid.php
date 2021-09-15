<?php
/**
 * Class Order_Service_InvoiceOverviewGrid
 */
class Order_Service_InvoiceOverviewGrid extends BAS_Shared_Service_Abstract
{
    /**
     * Get grid of invoices made for that vehicle
     *
     * @param Zend_Db_Select $source
     * @return Bvb_Grid
     * @throws Bvb_Grid_Exception
     * @throws Zend_Exception
     */
    public function getGrid(Zend_Db_Select $source): Bvb_Grid
    {
        $columns = $this->getGridColumns();

        $gridConfig = BAS_Shared_Registry::get('gridConfig');
        /** @var Bvb_Grid_Deploy_Table $grid */
        $grid = Bvb_Grid::factory('Table', $gridConfig);

        $basGrid = new Bvb_BasGrid();
        $basGrid
            ->setGrid($grid)
            ->setSource($grid, 0, $source)
            ->setColumns($grid, $columns)
            ->setFilters($grid, []);

        $grid->deploy();

        return $grid;
    }

    /**
     * Grid columns
     *
     * @return array
     */
    private function getGridColumns(): array
    {
        return [
            'invoice_id' => [
                'title' => 'invoice_id',
                'noOrder' => true,
            ],
            'debtor_id' => [
                'title' => 'debtor_id',
                'noOrder' => true,
            ],
            'invoice_address_company' => [
                'title' => 'invoice_address_company',
                'noOrder' => true,
            ],
            'total_price' => [
                'title' => 'total_price',
                'noOrder' => true,
            ],
        ];
    }
}