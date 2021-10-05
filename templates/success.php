<?php

try {
    $orderPage = merx()->completePayment($_GET);
    go($orderPage->url());
} catch (Exception $ex) {
    echo $ex->getMessage();
}
