![alt text][logo]
[logo]: http://www.synolia.com/wp-content/uploads/2016/03/Prestaneo-by-Synolia1.jpg "Prestaneo : Akeneo to prestashop by Synolia"

# PRESTANEO by Synolia

**Prestaneo** is a **Prestashop addon** allowing to import catalogs and product data, from the **Akeneo** standard CSV files.

## How it works
Prestaneo reads standard CSV files from the Akeneo exports in order to import data into your PrestaShop database. These data exchanges allow to import:
* **Categories**
* **Products**
* **Media** (Images, PDF files...)
* **Attributes, characteristics & declinations**

## Features
* **Plug and Play**: We payed particular attention to make the code as “transparent” as possible. It was thought as a real new application, so it can’t interfere with the native code of Prestashop.
* **Totally flexible**: We added plenty of configurations. We didn't write any specific value in our code: **no hard coding**.
* **Manual or automatic**: You can either upload and import your CSV files from the Prestashop back-office or simply let the automatic cron tasks run different import with CSV files located in a directory of your server.
* **Fast import**: Prestaneo uses CSV files and optimized SQL requests to be even more efficient.
* **Multi-site**: Prestaneo is natively compatible with the “multi-store” and the “multi-language” features of Prestashop.

## Requirements
**Prestaneo** is designed to work on **Prestashop 1.5+** based on generated CSV files from **Akeneo 1.3+**

PHP : **PHP 5.3+**

**Synolia** recommends to work with the **[Akeneo Enhanced Connector Bundle] (https://github.com/akeneo-labs/EnhancedConnectorBundle)** in order to be more efficient in your interfaces. This bundle allows you to go further to select the right data to export.

## How to install
### Manually
Prestaneo's installation is rather simple ! You only need to follow theses few steps:
* **Copy the module’s files** in the “modules” folder of Prestashop
* **Launch** the install process from your Prestashop Back-office
* **Enjoy** your fully parameterized Prestaneo instance !

## Configuration and usage
Multiple configurations are available in the addon graphic interface:
* **General configurations. Here you can:**
    * Manage the IP of entities that can call the automatic tasks URLs. 
    * Define the ftp or sftp connection information.
    * Define the distant server path for each of CSV files. 
* **Settings**: you can choose which of the status you want to show on the dashboard.
* **CSV settings**: choose enclosure and delimiter of your CSV files.
* **Import settings:**
    * Set a default value of stock for your products
    * Reset combinations before import them
    * Reset images before import them
    * Reset features before import them
* Other settings are **mappings between CSV columns and Prestashop attributes**.

## Current version 
1.0 : It's just you and me Prestashop!

## About Synolia

Founded in 2004, **[Synolia] (http://www.synolia.com)** is a French e-commerce & CRM company based in Lyon and Paris. With more than 650 projects in both B2B and B2C, Synolia is specialized in designing and delivering the best customer experience.

**Synolia** provides the most innovative solutions and is a certified partner of **Akeneo**, Magento, OroCRM, **PrestaShop** Salesfusion, SugarCRM, Qlik, Zendesk. Our ambition is to make each project a new success-story.

