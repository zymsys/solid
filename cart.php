<?php
function initialize()
{
    global $connection;

    session_start();
    $connection = new \PDO('mysql:host=localhost;dbname=solid', 'root', '');
    if (!isset($_SESSION['cart'])) {
        $connection->exec("INSERT INTO cart () VALUES ()");
        $_SESSION['cart'] = $connection->lastInsertId();
    }
}

function handleAdd()
{
    global $connection;

    if (!isset($_POST['addproduct'])) {
        return;
    }
    $sql = "INSERT INTO cartitem (cart, product, quantity) VALUES (:cart, :product, :quantity)
          ON DUPLICATE KEY UPDATE quantity = quantity + :quantity";
    $parameters = [
        'cart' => $_SESSION['cart'],
        'product' => $_POST['addproduct'],
        'quantity' => $_POST['quantity'],
    ];
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);
}

function handleUpdate()
{
    global $connection;

    if (!isset($_POST['update'])) {
        return;
    }
    $sql = "UPDATE cartitem SET quantity=:quantity WHERE cart=:cart and product=:product";
    $parameters = [
        'cart' => $_SESSION['cart'],
        'product' => $_POST['update'],
        'quantity' => $_POST['quantity'],
    ];
    $statement = $connection->prepare($sql);
    $statement->execute($parameters);
}

function loadCartItems()
{
    global $connection, $viewData;

    $viewData = [];
    $statement = $connection->prepare("SELECT * FROM cartitem WHERE cart=:cart AND quantity <> 0");
    $statement->execute(['cart' => $_SESSION['cart']]);
    return $statement->fetchAll();
}

function loadProducts()
{
    global $connection;

    $products = [];
    $result = $connection->query("SELECT * FROM product");
    foreach ($result as $product) {
        $products[$product['id']] = $product;
    }
    return $products;
}

function loadProvinces()
{
    global $connection;

    $provinces = [];
    $result = $connection->query("SELECT * FROM province ORDER BY name");
    foreach ($result as $row) {
        $provinces[$row['code']] = $row;
    }
    return $provinces;
}

function calculateCartSubtotal($cartItems, $products)
{
    $subtotal = 0;

    foreach ($cartItems as $cartItem) {
        $product = $products[$cartItem['product']];
        $subtotal += $cartItem['quantity'] * $product['price'];
    }

    return $subtotal;
}

function calculateCartTaxes($cartItems, $products, $taxrate)
{
    $taxable = 0;

    foreach ($cartItems as $cartItem) {
        $product = $products[$cartItem['product']];
        $taxable += $product['taxes'] ? $cartItem['quantity'] * $product['price'] : 0;
    }
    return $taxable * $taxrate / 100;
}

function buildViewData()
{
    $viewData =  [
        'cartItems' => loadCartItems(),
        'products' => loadProducts(),
        'provinces' => loadProvinces(),
        'provinceCode' => isset($_GET['province']) ? $_GET['province'] : 'ON', //Default to GTA-PHP's home
    ];

    foreach ($viewData['provinces'] as $province) {
        if ($province['code'] === $viewData['provinceCode']) {
            $viewData['province'] = $province;
        }
    }

    $viewData['subtotal'] = calculateCartSubtotal($viewData['cartItems'], $viewData['products']);
    $viewData['taxes'] = calculateCartTaxes($viewData['cartItems'], $viewData['products'], $viewData['province']['taxrate']);
    $viewData['total'] = $viewData['subtotal'] + $viewData['taxes'];

    return $viewData;
}
initialize();
handleAdd();
handleUpdate();

$viewData = buildViewData();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>GTA-PHP Gift Shop</title>
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap.min.css"
          integrity="sha384-1q8mTJOASx8j1Au+a5WDVnPi2lkFfwwEAa8hDDdjZlpLegxhjVME1fgjWPGmkzs7" crossorigin="anonymous">
    <link rel="stylesheet" href="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.6/css/bootstrap-theme.min.css"
          integrity="sha384-fLW2N01lMqjakBkx3l/M9EahuwpSfeNvV63J5ezn3uZzapT0u7EYsXMjQV+0En5r" crossorigin="anonymous">
</head>
<body>
<div class="container">
    <h1>GTA-PHP Gift Shop</h1>
    <p>Buy our junk to keep our organizers up to date with the latest gadgets.</p>
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
                <td><?php echo number_format(($product['price'] - $product['cost']) / 100, 2); ?></td>
                <td>
                    <form method="post">
                        <input type="number" name="quantity" value="1" style="width: 3em">
                        <input type="hidden" name="addproduct" value="<?php echo $product['id']; ?>">
                        <input class="btn btn-default btn-xs" type="submit" value="Add to Cart">
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
                            <input type="hidden" name="update" value="<?php echo $product['id']; ?>">
                            <input type="number" name="quantity" style="width: 3em"
                                   value="<?php echo $cartItem['quantity']; ?>">
                            <button type="submit">Update</button>
                        </form>
                    </td>
                    <td>
                        <?php
                        echo number_format($cartItem['quantity'] * $product['price'] / 100, 2);
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
                    $selected = $viewData['provinceCode'] === $province['code'] ? 'selected' : '';
                    ?>
                    <option value="<?php echo $province['code']; ?>" <?php echo $selected; ?>>
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
                       value="<?php echo $product['name'] . '|' . number_format($product['price'] / 100, 2); ?>">
            <?php endforeach; ?>
            <input type="hidden" name="item<?php echo count($viewData['cartItems']); ?>"
                   value="<?php echo 'Tax|' . number_format($viewData['taxes'] / 100, 2); ?>">
            <button type="submit" class="btn btn-primary" style="float: right">Checkout</button>
        </form>
    <?php endif; ?>
</div>
</body>
</html>