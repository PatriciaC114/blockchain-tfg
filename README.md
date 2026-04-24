# Blockchain Simulation (TFG)

## Objective

This project is a web-based simulation developed as part of a Bachelor's thesis (TFG).  
Its goal is to illustrate key blockchain and cryptography concepts in a practical way.

## Features

- Blockchain structure with blocks and transactions
- Merkle tree computation
- Simple Proof-of-Work mining mechanism
- Digital signatures using ECDSA (secp256k1)
- User network simulation (Markov chain model)
- Bitcoin halving model and reward calculation
- Custom halving simulator with user-defined parameters

## Technologies

- PHP
- HTML / CSS / JavaScript
- MySQL
- WAMP (Apache local server)
- GMP library for big integer arithmetic

## How to run

1. Install WAMP or XAMPP
2. Copy the project into the `www` or `htdocs` folder
3. Start Apache and MySQL
4. Open browser and go to:
   http://localhost/TFG/

## Project structure

- index.php → Blockchain simulation
- firmas.php → Digital signatures (ECDSA)
- red.php → User network simulation
- halving.php → Bitcoin halving model
- halving2.php → Custom halving simulator
- /assets → CSS and static files
- /includes → Shared navigation

## Academic context

Developed as part of a Bachelor's Thesis (TFG) focused on blockchain, cryptography, and distributed systems simulation.
