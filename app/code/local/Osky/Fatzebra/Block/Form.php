<?php
class Osky_Fatzebra_Block_Form extends Mage_Payment_Block_Form_Cc
{
    protected function _construct()
    {
        parent::_construct();
        $this->setTemplate('osky/fatzebra/form.phtml');
    }

}
