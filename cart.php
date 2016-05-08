<?php
class Application
{
    private $connection;
    private $inventory;
    private $sales;
    private $accounting;

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
    }

    public function buildViewData()
    {
        $viewData =  [
            'cartItems' => $this->sales->loadCartItems(),
            'products' => $this->inventory->loadProducts(),
            'provinces' => $this->accounting->loadProvinces(),
            'provinceCode' => isset($_GET['province']) ?
                $_GET['province'] : 'ON', //Default to GTA-PHP's home
        ];

        foreach ($viewData['provinces'] as $province) {
            if ($province['code'] === $viewData['provinceCode']) {
                $viewData['province'] = $province;
            }
        }

        $viewData['subtotal'] = $this->accounting->
            calculateCartSubtotal($viewData['cartItems'], $viewData['products']);
        $viewData['taxes'] = $this->accounting->
            calculateCartTaxes($viewData['cartItems'],
            $viewData['products'], $viewData['province']['taxrate']);
        $viewData['total'] = $viewData['subtotal'] + $viewData['taxes'];

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

class Accounting {
    private $connection;

    public function __construct(\PDO $connection)
    {
        $this->connection = $connection;
    }


    public function loadProvinces()
    {
        $provinces = [];
        $result = $this->connection->query("SELECT * FROM province ORDER BY name");
        foreach ($result as $row) {
            $provinces[$row['code']] = $row;
        }
        return $provinces;
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

    public function calculateCartTaxes($cartItems, $products, $taxrate)
    {
        $taxable = 0;

        foreach ($cartItems as $cartItem) {
            $product = $products[$cartItem['product']];
            $taxable += $product['taxes'] ?
                $cartItem['quantity'] * $product['price'] : 0;
        }
        return $taxable * $taxrate / 100;
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
            <tr>
                <td><!-- Name --></td>
                <td style="text-align: right">
                    <?php echo $viewData['province']['name']; ?> taxes at
                    <?php echo $viewData['province']['taxrate'] ?>%:</td>
                <td>
                    <?php
                    echo number_format($viewData['taxes'] / 100, 2);
                    ?>
                </td>
            </tr>
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
            <input type="hidden"
                   name="item<?php echo count($viewData['cartItems']); ?>"
                   value="<?php echo 'Tax|' .
                       number_format($viewData['taxes'] / 100, 2); ?>">
            <button type="submit" class="btn btn-primary" style="float: right">
                Checkout</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>