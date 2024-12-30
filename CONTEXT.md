# Bayal - Application de Gestion des Ventes

## Vue d'ensemble
Bayal est une application de gestion des ventes qui permet de gérer les commerciaux, les clients, les produits et les ventes. L'application est construite avec Laravel (backend) et Vue.js avec Inertia.js (frontend), utilisant Vuetify pour l'interface utilisateur.

## Structure Technique

### Stack Technique
- Backend: Laravel
- Frontend: Vue.js 3
- Router: Inertia.js
- UI Framework: Vuetify 3
- Base de données: MySQL
- Authentication: Laravel Breeze

### Modèles de Données

#### Commercial
```php
- id
- name
- phone_number
- gender (male/female)
- secret_code (hashed)
- timestamps
```

Relations:
- Possède plusieurs clients (hasMany)
- Possède plusieurs ventes (hasMany)

#### Client (Customer)
```php
- id
- name
- phone_number
- owner_number
- gps_coordinates
- commercial_id
- timestamps
```

Relations:
- Appartient à un commercial (belongsTo)
- Possède plusieurs ventes (hasMany)

#### Produit (Product)
```php
- id
- name
- price
- timestamps
```

Relations:
- Possède plusieurs ventes (hasMany)

#### Vente
```php
- id
- product_id
- customer_id
- commercial_id
- quantity
- price
- paid (boolean)
- should_be_paid_at
- timestamps
```

Relations:
- Appartient à un produit (belongsTo)
- Appartient à un client (belongsTo)
- Appartient à un commercial (belongsTo)

### Fonctionnalités Principales

#### 1. Gestion des Commerciaux
- Liste des commerciaux avec leurs statistiques
- Ajout d'un nouveau commercial avec code secret
- Modification des informations d'un commercial
- Suppression d'un commercial (si pas de clients associés)
- Statistiques par commercial (nombre de clients, ventes, etc.)

#### 2. Gestion des Clients
- Liste des clients avec filtrage
- Ajout d'un nouveau client
- Attribution d'un commercial
- Géolocalisation des clients (coordonnées GPS)
- Visualisation sur Google Maps

#### 3. Gestion des Ventes
- Enregistrement des ventes
- Suivi des paiements
- Filtrage des ventes (par date, statut, commercial)
- Statistiques des ventes:
  - Montant total des ventes
  - Ventes payées vs impayées
  - Taux de paiement
  - Nombre de ventes par période

### Tableaux de Bord

#### Dashboard Principal
- Total des ventes
- Nombre de clients
- Nombre de commerciaux
- Montant des impayés

#### Dashboard Ventes
- Montant total des ventes
- Nombre de ventes payées
- Montant des ventes impayées
- Taux de paiement

### Sécurité
- Authentication utilisateur
- Hachage des codes secrets des commerciaux
- Validation des données
- Protection CSRF
- Middleware d'authentification

### Routes Principales
```php
Route::middleware('auth')->group(function () {
    Route::resource('commerciaux', CommercialController::class);
    Route::resource('clients', CustomerController::class);
    Route::resource('produits', ProductController::class);
    Route::resource('ventes', VenteController::class);
});
```

## Conventions de Code

### Frontend
- Composants Vue en composition API
- Utilisation de Vuetify pour l'UI
- Format monétaire: XOF sans décimales
- Format de date: fr-FR

### Backend
- Controllers RESTful
- Validation des requêtes
- Logging des opérations importantes
- Relations Eloquent

## Installation et Configuration

### Prérequis
- PHP 8.1+
- Node.js 16+
- MySQL 8.0+

### Installation
1. Cloner le repository
2. Installer les dépendances PHP: `composer install`
3. Installer les dépendances JS: `npm install`
4. Copier `.env.example` vers `.env`
5. Générer la clé: `php artisan key:generate`
6. Configurer la base de données dans `.env`
7. Lancer les migrations: `php artisan migrate`
8. Compiler les assets: `npm run dev`

### Développement
- Backend: `php artisan serve`
- Frontend: `npm run dev` 