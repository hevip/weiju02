<?php
/**
 * Created by PhpStorm.
 * User: greatsir
 * Date: 2018/2/9
 * Time: 上午10:21
 */

namespace baidu\lib;


class AipSignOption
{
    const EXPIRATION_IN_SECONDS = 'expirationInSeconds';

    const HEADERS_TO_SIGN = 'headersToSign';

    const TIMESTAMP = 'timestamp';

    const DEFAULT_EXPIRATION_IN_SECONDS = 1800;

    const MIN_EXPIRATION_IN_SECONDS = 300;

    const MAX_EXPIRATION_IN_SECONDS = 129600;
}