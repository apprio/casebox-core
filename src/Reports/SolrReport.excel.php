<?php
    use \Casebox\CoreBundle\Reports\Excel\Table;
?>
<div sheet-name="Hi">
	   <div>
<?php
        Table::create(array(
            "dataSource" => 'orders',
            "headersExcelStyle" => [
                'customerName' => [
                    'font' => [
                        'italic' => true,
                        'color' => [
                            'rgb' => '808080',
                        ]
                    ],
                ]
            ],
            "columnsExcelStyle" => [
                'customerName' => [
                    'font' => [
                        'italic' => true,
                        'color' => [
                            'rgb' => '808080',
                        ]
                    ],
                ]
            ],

        ));
        ?>
	  </div>
</div>