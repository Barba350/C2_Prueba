CREATE TABLE productos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    modelo VARCHAR(100) NOT NULL,
    precio DECIMAL(10,2) NOT NULL,
    stock INT NOT NULL,
    imagen VARCHAR(255) NULL
);

INSERT INTO productos (modelo, precio, stock) VALUES
('iPhone 15 Pro Max', 1450.00, 8),
('iPhone 15 Pro', 1250.00, 10),
('iPhone 15', 999.00, 15),
('iPhone 14', 799.00, 20),
('iPhone 13', 649.00, 12),
('iPhone SE', 449.00, 18);
