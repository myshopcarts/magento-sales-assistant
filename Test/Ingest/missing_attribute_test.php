<?php
/**
 * Regression: a store WITHOUT an attribute the serializer reads (brand / color / size …) must not
 * abort the catalogue rebuild. Magento's Product::getAttributeText($code) is
 * getResource()->getAttribute($code)->getSource()->getOptionText(…) — when the attribute doesn't
 * exist, getAttribute() returns false and it fatals "Call to a member function getSource() on false"
 * (seen 2026-10-08 on a fresh 2.4.8-p1 store with Performance Toolkit products; the whole
 * Sales Assistant catalogue reindex failed). ProductSerializer::attr() now checks first.
 *
 *     php Test/Ingest/missing_attribute_test.php   # exit 0 = pass, 1 = fail
 */
declare(strict_types=1);

$base = dirname(__DIR__, 2);
if (!interface_exists('Magento\\Catalog\\Api\\Data\\ProductInterface')) {
    eval('namespace Magento\\Catalog\\Api\\Data; interface ProductInterface {}');
}
require $base . '/Model/Ingest/ProductSerializer.php';

use Myshopcarts\SalesAssistant\Model\Ingest\ProductSerializer;

/** Mimics Magento: getAttributeText() derefs getAttribute($code) — fatal when it's false. */
final class FakeProduct implements \Magento\Catalog\Api\Data\ProductInterface
{
    public function __construct(private array $data, private array $known)
    {
    }

    public function getResource(): object
    {
        $known = $this->known;
        return new class($known) {
            public function __construct(private array $known)
            {
            }

            public function getAttribute(string $code)
            {
                return in_array($code, $this->known, true) ? new \stdClass() : false;
            }
        };
    }

    public function getAttributeText(string $code)
    {
        $attr = $this->getResource()->getAttribute($code);
        return $attr->getSource();   // Error on false, exactly like Magento; never reached for known attrs here
    }

    public function getData(string $code)
    {
        return $this->data[$code] ?? null;
    }
}

$FAILS = 0;
$check = static function (string $name, bool $ok) use (&$FAILS): void {
    echo ($ok ? '  PASS  ' : '  FAIL  ') . $name . "\n";
    if (!$ok) {
        $FAILS++;
    }
};

$s = (new \ReflectionClass(ProductSerializer::class))->newInstanceWithoutConstructor();
$attr = new \ReflectionMethod(ProductSerializer::class, 'attr');

echo "\n== missing attribute does not abort serialization\n";
try {
    $v = $attr->invoke($s, new FakeProduct(['brand' => 'raw'], []), 'brand');
    $check('missing attribute → falls back to raw data, no fatal', $v === 'raw');
} catch (\Throwable $e) {
    $check('missing attribute → falls back to raw data, no fatal (' . $e->getMessage() . ')', false);
}
try {
    $v = $attr->invoke($s, new FakeProduct([], []), 'brand');
    $check('missing attribute and no data → empty string', $v === '');
} catch (\Throwable $e) {
    $check('missing attribute and no data → empty string (' . $e->getMessage() . ')', false);
}

echo $FAILS === 0 ? "\nALL PASS\n" : "\n$FAILS FAILED\n";
exit($FAILS === 0 ? 0 : 1);
