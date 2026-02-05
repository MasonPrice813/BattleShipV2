# Battleship V2+

This is a web-based Battleship game built using PHP and CSS and run locally using XAMPP. The game uses PHP sessions to keep track of progress, so refreshing the page does not reset the game unless the player chooses to restart.

## Major Iterations

### Iteration 1 – Turn-Based Gameplay (Computer Fires Back)
In this iteration, the game was changed to be fully turn-based. After the player fires a shot, the computer immediately takes its turn and fires back. The game then updates the boards and checks for win or loss conditions. This makes the game feel like a real Battleship match instead of a one-sided experience.

### Iteration 2 – Smarter AI with Difficulty Levels
This iteration added multiple AI difficulty levels that change how the computer behaves:

- **Easy:** The computer fires randomly at any unguessed cell.
- **Medium:** After getting a hit, the computer targets nearby cells to try to sink the ship.
- **Hard:** The computer uses a hunt-and-target strategy, remembers previous hits, uses parity searching, and extends shots once it detects a ship’s orientation.

## Known Limitations
- Ships are placed automatically; the player cannot manually place ships.
- Game state is stored using PHP sessions, so the game resets if Apache is restarted in XAMPP or the session is cleared.

## How to Run the Program: 
Place the BattleshipV2 folder into the htdocs folder. This is the exact path on Windows: "C:\xampp\htdocs\BattleshipV2"

The localhost URL is: http://localhost/BattleshipV2/

LINK TO LOOM: 
https://www.loom.com/share/d42a6e2ff42849319c6de7ad6df82ae6

The Loom is also attached as as a downloadable mp4 to the github.
