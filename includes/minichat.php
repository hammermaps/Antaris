<?php
header("Content-Type: text/html; charset=iso-8859-1"); // On crée un header qui formate la page en texte
$mysqli = new mysqli("localhost", "antarisl_thisis", "EJceRpJcPv3181", "antarisl_ingame"); // On ouvre une connexion à la base de données avec nos identifiants
if (isset($_POST['message']))  // Si on reçoit des données via une méthode POST
{
    if(!empty($_POST['message'])) // Et si leur valeur n'est ni NULL, ni vide
    {
	$message = $mysqli->real_escape_string(mb_convert_encoding($_POST['message'], 'ISO-8859-1', 'UTF-8'));
        
        $mysqli->query("INSERT INTO uni1_minichat (pseudo,message,timestamp) VALUES('pseudo', '".$message."', '".time()."')");
    }
}
$reponse = $mysqli->query("SELECT * FROM uni1_minichat");
while($val = $reponse->fetch_assoc())
{
	echo '<div data-id_message="933321" name="message"> <div name="avatar"><img onclick="javascript:Tchat.action("chuchoter", "Klorel");" onmouseover="montre("<b>Cliquez ici pour chuchoter avec Klorel :</b><br />                                                        <i>Il est possible d`envoyer un message uniquement à Klorel<br />via le tchat par le biais cette fonction.</i>");" onmouseout="cache();" src="/media/avatar/mini_340.png?1427911237"></div><div name="information"><span onclick="javascript:Tchat.action("adresser", "Klorel");" name="pseudo">Klorel</span><a onclick="javascript:ActionMethode.ouvrirPagePrincipale("alliance.php", "tag=EMG");" onmouseover="montre("Afficher la description de cette alliance.");" onmouseout="cache();" class="couleur_alliance"> [EMG]</a><span name="date_heure">Le 01/04 à 20:32:57</span></div><div name="texte"><span style="color : #FF6600" class="bbcode">Vend OR <img class="smiley" src="design/image/smiley/heureux.png" alt=":)"></span></div><div class="espace"></div></div>';
}
 
// On affiche les messages de notre chat ici
 
$mysqli->close(); // On ferme la connexion de notre base de données
?>