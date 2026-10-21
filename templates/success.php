<?php

try {
    /** SECURITY/PRIVACY: Never (server) cache this response, otherwise user could be redirected to false order page */
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, s-maxage=0');
    header('Expires: 0');
    $orderPage = merx()->completePayment($_GET);
    go($orderPage->url());
} catch (Exception $ex) {
    echo $ex->getMessage();
}
