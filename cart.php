<?php
class Application
{
    private $connection;
    private $inventory;
    private $sales;
    private $accounting;
    private $products;
    private $provinces;
    private $selectedProvince;

    public function __construct()
    {
        $this->connection = new \PDO('mysql:host=localhost;dbname=solid', 'root', '');
        $this->inventory = new Inventory($this->connection);
        $this->sales = new Sales($this->connection);
        $this->accounting = new Accounting($this->connection);
    }

    public function initialize()
    {
        session_start();
        if (!isset($_SESSION['cart'])) {
            $this->connection->exec("INSERT INTO cart VALUES ()");
            $_SESSION['cart'] = $this->connection->lastInsertId();
        }
        $this->handlePost();

        $this->products = $this->inventory->loadProducts();
        $provinceRepository = new ProvinceRepository($this->connection, isset($_GET['province']) ?
            $_GET['province'] : 'ON');
        $this->provinces = $provinceRepository->loadProvinces();
        $this->selectedProvince = $provinceRepository->getSelectedProvince();
        $this->accounting->addStrategy(
            new TaxAccountingStrategy($this->products, $provinceRepository->getSelectedProvince())
        );
        $this->accounting->addStrategy(
            new DiscountAccountingStrategy($this->products)
        );
    }

    public function buildViewData()
    {
        $cartItems = $this->sales->loadCartItems();
        $viewData =  [
            'cartItems' => $cartItems,
            'products' => $this->inventory->loadProducts(),
            'provinces' => $this->provinces,
            'adjustments' => $this->accounting->applyAdjustments($cartItems),
            'provinceCode' => $this->selectedProvince['code'],
        ];

        foreach ($viewData['provinces'] as $province) {
            if ($province['code'] === $viewData['provinceCode']) {
                $viewData['province'] = $province;
            }
        }

        $viewData['subtotal'] = $this->accounting->
            calculateCartSubtotal($viewData['cartItems'], $viewData['products']);
        $viewData['total'] = $viewData['subtotal'] + $this->accounting->getAppliedAdjustmentsTotal();

        return $viewData;
    }

    private function handlePost()
    {
        if (isset($_POST['addproduct'])) {
            $this->sales->addProductToCart(
                $_SESSION['cart'],
                $_POST['addproduct'],
                $_POST['quantity']
            );
        }
        if (isset($_POST['update'])) {
            $this->sales->modifyProductQuantityInCart(
                $_SESSION['cart'],
                $_POST['update'],
                $_POST['quantity']
            );
        }
    }
}

class Inventory {
    private $connection;

    public function __construct(\PDO $connection)
    {
        $this->connection = $connection;
    }

    public function loadProducts()
    {
        $products = [];
        $result = $this->connection->query("SELECT * FROM product");
        foreach ($result as $product) {
            $products[$product['id']] = $product;
        }
        return $products;
    }
}

class Sales {
    private $connection;

    public function __construct(\PDO $connection)
    {
        $this->connection = $connection;
    }

    public function addProductToCart($cartId, $productId, $quantity)
    {
        $sql = "INSERT INTO cartitem (cart, product, quantity) 
          VALUES (:cart, :product, :quantity)
          ON DUPLICATE KEY UPDATE quantity = quantity + :quantity";
        $parameters = [
            'cart' => $cartId,
            'product' => $productId,
            'quantity' => $quantity,
        ];
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
    }

    public function modifyProductQuantityInCart($cartId, $productId, $quantity)
    {
        $sql = "UPDATE cartitem SET quantity=:quantity 
          WHERE cart=:cart and product=:product";
        $parameters = [
            'cart' => $cartId,
            'product' => $productId,
            'quantity' => $quantity,
        ];
        $statement = $this->connection->prepare($sql);
        $statement->execute($parameters);
    }

    public function loadCartItems()
    {
        $statement = $this->connection->prepare("SELECT * FROM cartitem 
      WHERE cart=:cart AND quantity <> 0");
        $statement->execute(['cart' => $_SESSION['cart']]);
        return $statement->fetchAll();
    }
}

class ProvinceRepository
{
    private $connection;
    private $provinces = null;
    private $selectedProvince;
    private $selectedProvinceCode;

    public function __construct(\PDO $connection, $selectedProvinceCode)
    {
        $this->connection = $connection;
        $this->selectedProvinceCode = $selectedProvinceCode;
    }

    public function loadProvinces()
    {
        $this->provinces = [];
        $result = $this->connection->query("SELECT * FROM province ORDER BY name");
        foreach ($result as $row) {
            $this->provinces[$row['code']] = $row;
            if ($row['code'] === $this->selectedProvinceCode)
            {
                $this->selectedProvince = $row;
            }
        }
        return $this->provinces;
    }

    public function getProvinces()
    {
        return is_null($this->provinces) ? $this->loadProvinces() : $this->provinces;
    }

    public function getSelectedProvince()
    {
        return $this->selectedProvince;
    }
}

class AccountingAdjustment {
    private $description;
    private $amount;

    public function __construct($description, $amount)
    {
        $this->description = $description;
        $this->amount = $amount;
    }

    public function getDescription()
    {
        return $this->description;
    }

    public function getAmount()
    {
        return $this->amount;
    }
}

class AccountingStrategy {
    private $description;

    public function __construct($description)
    {
        $this->description = $description;
    }

    public function getAdjustment($cartItems)
    {
        return false;
    }

    public function getDescription()
    {
        return $this->description;
    }
}

class TaxAccountingStrategy extends AccountingStrategy {
    private $products;
    private $taxRate;

    public function __construct($products, $province)
    {
        parent::__construct($province['name'] . ' taxes at ' .
            $province['taxrate'] . '%:');
        $this->products = $products;
        $this->taxRate = $province['taxrate'];
    }

    public function getAdjustment($cartItems)
    {
        $taxable = 0;

        foreach ($cartItems as $cartItem) {
            $product = $this->products[$cartItem['product']];
            $taxable += $product['taxes'] ?
                $cartItem['quantity'] * $product['price'] : 0;
        }
        return $taxable * $this->taxRate / 100;
    }
}

class DiscountAccountingStrategy extends AccountingStrategy {
    private $products;

    public function __construct($products)
    {
        parent::__construct("Discount for orders over $100");
        $this->products = $products;
    }

    public function getAdjustment($cartItems)
    {
        $total = array_reduce($cartItems, function ($carry, $item) {
            $product = $this->products[$item['product']];
            return $carry + $item['quantity'] * $product['price'];
        }, 0);
        return $total > 10000 ? ($total / -10) : false;
    }
}

class Accounting {
    private $connection;
    private $strategies = [];
    private $appliedAdjustments = 0;

    public function __construct(\PDO $connection)
    {
        $this->connection = $connection;
    }

    public function calculateCartSubtotal($cartItems, $products)
    {
        $subtotal = 0;

        foreach ($cartItems as $cartItem) {
            $product = $products[$cartItem['product']];
            $subtotal += $cartItem['quantity'] * $product['price'];
        }

        return $subtotal;
    }

    public function addStrategy($strategy)
    {
        $this->strategies[] = $strategy;
    }

    public function applyAdjustments($cartItems)
    {
        $adjustments = [];
        foreach ($this->strategies as $strategy) {
            $adjustment = $strategy->getAdjustment($cartItems);
            if ($adjustment) {
                $this->appliedAdjustments += $adjustment;
                $adjustments[] = [
                    'description' => $strategy->getDescription(),
                    'adjustment' => $adjustment,
                ];
            }
        }
        return $adjustments;
    }

    public function getAppliedAdjustmentsTotal()
    {
        return $this->appliedAdjustments;
    }
}

$app = new Application();
$app->initialize();
$viewData = $app->buildViewData();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>GTA-PHP Gift Shop</title>
    <link rel="stylesheet" href="site.css">
</head>
<body>
<div class="container">
    <h1>GTA-PHP Gift Shop</h1>
    <p>Buy our junk to keep our organizers up to date
        with the latest gadgets.</p>
    <table class="table">
        <tr>
            <th>Product Name</th>
            <th>You Pay</th>
            <th>Group Gets</th>
            <th><!-- Column for add to cart button --></th>
        </tr>
            <?php foreach ($viewData['products'] as $product): ?>
            <tr>
                <td><?php echo $product['name']; ?></td>
                <td><?php
                    $price = $product['price'];
                    echo number_format($price / 100, 2);
                    ?></td>
                <td><?php
                    echo number_format(
                        ($product['price'] - $product['cost']) / 100, 2
                    );
                    ?></td>
                <td>
                    <form method="post">
                        <input type="number" name="quantity"
                               value="1" style="width: 3em">
                        <input type="hidden" name="addproduct"
                               value="<?php echo $product['id']; ?>">
                        <input class="btn btn-default btn-xs"
                               type="submit" value="Add to Cart">
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
    </table>
    <?php if (count($viewData['cartItems']) > 0): ?>
        <h2>Your Cart:</h2>
        <table class="table">
            <?php foreach ($viewData['cartItems'] as $cartItem): ?>
                <?php $product = $viewData['products'][$cartItem['product']]; ?>
                <tr>
                    <td>
                        <?php echo $product['name']; ?>
                    </td>
                    <td>
                        <form method="post">
                            Quantity:
                            <input type="hidden" name="update"
                                   value="<?php echo $product['id']; ?>">
                            <input type="number" name="quantity" style="width: 3em"
                                   value="<?php echo $cartItem['quantity']; ?>">
                            <button type="submit">Update</button>
                        </form>
                    </td>
                    <td>
                        <?php
                        echo number_format(
                            $cartItem['quantity'] * $product['price'] / 100, 2
                        );
                        ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td><!-- Name --></td>
                <td style="text-align: right">Subtotal:</td>
                <td><?php echo number_format($viewData['subtotal'] / 100, 2); ?></td>
            </tr>
            <?php foreach ($viewData['adjustments'] as $adjustment): ?>
            <tr>
                <td><!-- Name --></td>
                <td style="text-align: right">
                    <?php echo $adjustment['description']; ?>
                </td>
                <td>
                    <?php
                    echo number_format($adjustment['adjustment'] / 100, 2);
                    ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <tr>
                <td><!-- Name --></td>
                <td style="text-align: right">Total:</td>
                <td><?php echo number_format($viewData['total'] / 100, 2); ?></td>
            </tr>
        </table>
        <form method="get">
            Calculate taxes for purchase from:
            <select name="province">
                <?php foreach ($viewData['provinces'] as $province): ?>
                    <?php
                    $selected = $viewData['provinceCode'] ===
                        $province['code'] ? 'selected' : '';
                    ?>
                    <option value="<?php echo $province['code']; ?>"
                        <?php echo $selected; ?>>
                        <?php echo $province['name']; ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-default btn-xs">Recalculate</button>
        </form>
        <form action="checkout.php" method="post">
            <?php foreach ($viewData['cartItems'] as $itemNumber => $cartItem): ?>
                <?php
                $product = $viewData['products'][$cartItem['product']];
                ?>
                <input type="hidden" name="item<?php echo $itemNumber; ?>"
                       value="<?php
                       echo $product['name'] . '|' .
                           number_format($product['price'] / 100, 2);
                       ?>">
            <?php endforeach; ?>
            <?php
            for ($adjustmentIndex = 0; $adjustmentIndex < count($viewData['adjustments']); $adjustmentIndex += 1):
                $adjustment = $viewData['adjustments'][$adjustmentIndex];
            ?>
            <input type="hidden"
                   name="item<?php echo $adjustmentIndex; ?>"
                   value="<?php echo $adjustment['description'] . '|' .
                       number_format($adjustment['adjustment'] / 100, 2); ?>">
            <?php endfor; ?>
            <button type="submit" class="btn btn-primary" style="float: right">
                Checkout</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>