<?php
/* Pi-hole: A black hole for Internet advertisements
*  (c) 2017 Pi-hole, LLC (https://pi-hole.net)
*  Network-wide ad blocking via your own hardware.
*
*  This file is copyright under the latest version of the EUPL.
*  Please see LICENSE file for your rights under this license. */

if(!in_array(basename($_SERVER['SCRIPT_FILENAME']), ["settings.php", "teleporter.php"], true))
{
	die("Direct access to this script is forbidden!");
}

function validIP($address){
	if (preg_match('/[.:0]/', $address) && !preg_match('/[1-9a-f]/', $address)) {
		// Test if address contains either `:` or `0` but not 1-9 or a-f
		return false;
	}
	return !filter_var($address, FILTER_VALIDATE_IP) === false;
}

function validCIDRIP($address){
	// This validation strategy has been taken from ../js/groups-common.js
	$isIPv6 = strpos($address, ":") !== false;
	if($isIPv6) {
		// One IPv6 element is 16bit: 0000 - FFFF
		$v6elem = "[0-9A-Fa-f]{1,4}";
		// CIDR for IPv6 is any multiple of 4 from 4 up to 128 bit
		$v6cidr = "(4";
		for ($i=8; $i <= 128; $i+=4) {
			$v6cidr .= "|$i";
		}
		$v6cidr .= ")";
		$validator = "/^(((?:$v6elem))((?::$v6elem))*::((?:$v6elem))((?::$v6elem))*|((?:$v6elem))((?::$v6elem)){7})\/$v6cidr$/";
		return preg_match($validator, $address);
	} else {
		// One IPv4 element is 8bit: 0 - 256
		$v4elem = "(25[0-5]|2[0-4][0-9]|1[0-9][0-9]|[1-9][0-9]?|0)";
		// Note that rev-server accepts only /8, /16, /24, and /32
		$allowedv4cidr = "(8|16|24|32)";
		$validator = "/^$v4elem\.$v4elem\.$v4elem\.$v4elem\/$allowedv4cidr$/";
		return preg_match($validator, $address);
	}
}

// Check for existance of variable
// and test it only if it exists
function istrue(&$argument) {
	if(isset($argument))
	{
		if($argument)
		{
			return true;
		}
	}
	return false;
}

// Credit: http://stackoverflow.com/a/4694816/2087442
function validDomain($domain_name)
{
	$validChars = preg_match("/^([_a-z\d](-*[_a-z\d])*)(\.([_a-z\d](-*[a-z\d])*))*(\.([_a-z\d])*)*$/i", $domain_name);
	$lengthCheck = preg_match("/^.{1,253}$/", $domain_name);
	$labelLengthCheck = preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $domain_name);
	return ( $validChars && $lengthCheck && $labelLengthCheck ); //length of each label
}

function validDomainWildcard($domain_name)
{
	// There has to be either no or at most one "*" at the beginning of a line
	$validChars = preg_match("/^((\*\.)?[_a-z\d](-*[_a-z\d])*)(\.([_a-z\d](-*[a-z\d])*))*(\.([_a-z\d])*)*$/i", $domain_name);
	$lengthCheck = preg_match("/^.{1,253}$/", $domain_name);
	$labelLengthCheck = preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $domain_name);
	return ( $validChars && $lengthCheck && $labelLengthCheck ); //length of each label
}

function validMAC($mac_addr)
{
  // Accepted input format: 00:01:02:1A:5F:FF (characters may be lower case)
  return !filter_var($mac_addr, FILTER_VALIDATE_MAC) === false;
}

function formatMAC($mac_addr)
{
	preg_match("/([0-9a-fA-F]{2}[:]){5}([0-9a-fA-F]{2})/", $mac_addr, $matches);
	if(count($matches) > 0)
		return $matches[0];
	return null;
}

function validEmail($email)
{
	return filter_var($email, FILTER_VALIDATE_EMAIL)
		// Make sure that the email does not contain special characters which
		// may be used to execute shell commands, even though they may be valid
		// in an email address. If the escaped email does not equal the original
		// email, it is not safe to store in setupVars.
		&& escapeshellcmd($email) === $email;
}

$dhcp_static_leases = array();
function readStaticLeasesFile($origin_file="/etc/dnsmasq.d/04-pihole-static-dhcp.conf")
{
	global $dhcp_static_leases;
	$dhcp_static_leases = array();
	if(!file_exists($origin_file) || !is_readable($origin_file))
		return false;

	$dhcpstatic = @fopen($origin_file, 'r');
	if(!is_resource($dhcpstatic))
		return false;

	while(!feof($dhcpstatic))
	{
		// Remove any possibly existing variable with this name
		$mac = ""; $one = ""; $two = "";
		sscanf(trim(fgets($dhcpstatic)),"dhcp-host=%[^,],%[^,],%[^,]",$mac,$one,$two);
		if(strlen($mac) > 0 && validMAC($mac))
		{
			if(validIP($one) && strlen($two) == 0)
				// dhcp-host=mac,IP - no HOST
				array_push($dhcp_static_leases,["hwaddr"=>$mac, "IP"=>$one, "host"=>""]);
			elseif(strlen($two) == 0)
				// dhcp-host=mac,hostname - no IP
				array_push($dhcp_static_leases,["hwaddr"=>$mac, "IP"=>"", "host"=>$one]);
			else
				// dhcp-host=mac,IP,hostname
				array_push($dhcp_static_leases,["hwaddr"=>$mac, "IP"=>$one, "host"=>$two]);
		}
		else if(validIP($one) && validDomain($mac))
		{
			// dhcp-host=hostname,IP - no MAC
			array_push($dhcp_static_leases,["hwaddr"=>"", "IP"=>$one, "host"=>$mac]);
		}
	}
	return true;
}

function isequal(&$argument, &$compareto) {
	if(isset($argument))
	{
		if($argument === $compareto)
		{
			return true;
		}
	}
	return false;
}

function isinserverlist($addr) {
	global $DNSserverslist;
	foreach ($DNSserverslist as $key => $value) {
		if (isequal($value['v4_1'],$addr) || isequal($value['v4_2'],$addr))
			return true;
		if (isequal($value['v6_1'],$addr) || isequal($value['v6_2'],$addr))
			return true;
	}
	return false;
}

$DNSserverslist = [];
function readDNSserversList()
{
	// Reset list
	$list = [];
	$handle = @fopen("/etc/pihole/dns-servers.conf", "r");
	if ($handle)
	{
		while (($line = fgets($handle)) !== false)
		{
			$line = rtrim($line);
			$line = explode(';', $line);
			$name = $line[0];
			$values = [];
			if (!empty($line[1]) && validIP($line[1])) {
				$values["v4_1"] = $line[1];
			}
			if (!empty($line[2]) && validIP($line[2])) {
				$values["v4_2"] = $line[2];
			}
			if (!empty($line[3]) && validIP($line[3])) {
				$values["v6_1"] = $line[3];
			}
			if (!empty($line[4]) && validIP($line[4])) {
				$values["v6_2"] = $line[4];
			}
            $list[$name] = $values;
		}
		fclose($handle);
	}
	return $list;
}

require_once("database.php");

function addStaticDHCPLease($mac, $ip, $hostname) {
	global $error, $success, $dhcp_static_leases;

	try {
		if(!validMAC($mac))
		{
			throw new Exception("MAC address (".htmlspecialchars($mac).") is invalid!<br>", 0);
		}
		$mac = strtoupper($mac);

		if(!validIP($ip) && strlen($ip) > 0)
		{
			throw new Exception("IP address (".htmlspecialchars($ip).") is invalid!<br>", 1);
		}

		if(!validDomain($hostname) && strlen($hostname) > 0)
		{
			throw new Exception("Host name (".htmlspecialchars($hostname).") is invalid!<br>", 2);
		}

		if(strlen($hostname) == 0 && strlen($ip) == 0)
		{
			throw new Exception("You can not omit both the IP address and the host name!<br>", 3);
		}

		if(strlen($hostname) == 0)
			$hostname = "nohost";

		if(strlen($ip) == 0)
			$ip = "noip";

		// Test if this lease is already included
		readStaticLeasesFile();

		foreach($dhcp_static_leases as $lease) {
			if($lease["hwaddr"] === $mac)
			{
				throw new Exception("Static lease for MAC address (".htmlspecialchars($mac).") already defined!<br>", 4);
			}
			if($ip !== "noip" && $lease["IP"] === $ip)
			{
				throw new Exception("Static lease for IP address (".htmlspecialchars($ip).") already defined!<br>", 5);
			}
			if($lease["host"] === $hostname)
			{
				throw new Exception("Static lease for hostname (".htmlspecialchars($hostname).") already defined!<br>", 6);
			}
		}

		pihole_execute("-a addstaticdhcp ".$mac." ".$ip." ".$hostname);
		$success .= "A new static address has been added";
		return true;
	} catch(Exception $exception) {
		$error .= $exception->getMessage();
		return false;
	}
}

	// Read available DNS server list
	$DNSserverslist = readDNSserversList();

	$error = "";
	$success = "";

	if(isset($_POST["field"]))
	{
		// Handle CSRF
		check_csrf(isset($_POST["token"]) ? $_POST["token"] : "");

		// Process request
		switch ($_POST["field"]) {
			// Set DNS server
			case "DNS":

				$DNSservers = [];
				// Add selected predefined servers to list
				foreach ($DNSserverslist as $key => $value)
				{
					foreach(["v4_1", "v4_2", "v6_1", "v6_2"] as $type)
					{
						if(@array_key_exists("DNSserver".str_replace(".","_",$value[$type]),$_POST))
						{
							array_push($DNSservers,$value[$type]);
						}
					}
				}

				// Test custom server fields
				for($i=1;$i<=4;$i++)
				{
					if(array_key_exists("custom".$i,$_POST))
					{
						$exploded = explode("#", $_POST["custom".$i."val"], 2);
						$IP = trim($exploded[0]);

						if(!validIP($IP))
						{
							$error .= "IP (".htmlspecialchars($IP).") is invalid!<br>";
						}
						else
						{
							if(count($exploded) > 1)
							{
								$port = trim($exploded[1]);
								if(!is_numeric($port))
								{
									$error .= "Port (".htmlspecialchars($port).") is invalid!<br>";
								}
								else
								{
									$IP .= "#".$port;
								}
							}

							array_push($DNSservers,$IP);
						}
					}
				}
				$DNSservercount = count($DNSservers);

				// Check if at least one DNS server has been added
				if($DNSservercount < 1)
				{
					$error .= "No DNS server has been selected.<br>";
				}

				// Check if domain-needed is requested
				if(isset($_POST["DNSrequiresFQDN"]))
				{
					$extra = "domain-needed ";
				}
				else
				{
					$extra = "domain-not-needed ";
				}

				// Check if domain-needed is requested
				if(isset($_POST["DNSbogusPriv"]))
				{
					$extra .= "bogus-priv ";
				}
				else
				{
					$extra .= "no-bogus-priv ";
				}

				// Check if DNSSEC is requested
				if(isset($_POST["DNSSEC"]))
				{
					$extra .= "dnssec";
				}
				else
				{
					$extra .= "no-dnssec";
				}

				// Check if rev-server is requested
				if(isset($_POST["rev_server"]))
				{
					// Validate CIDR IP
					$cidr = trim($_POST["rev_server_cidr"]);
					if (!validCIDRIP($cidr))
					{
						$error .= "Conditional forwarding subnet (\"".htmlspecialchars($cidr)."\") is invalid!<br>".
						          "This field requires CIDR notation for local subnets (e.g., 192.168.0.0/16).<br>".
						          "Please use only subnets /8, /16, /24, and /32.<br>";
					}

					// Validate target IP
					$target = trim($_POST["rev_server_target"]);
					if (!validIP($target))
					{
						$error .= "Conditional forwarding target IP (\"".htmlspecialchars($target)."\") is invalid!<br>";
					}

					// Validate conditional forwarding domain name (empty is okay)
					$domain = trim($_POST["rev_server_domain"]);
					if(strlen($domain) > 0 && !validDomain($domain))
					{
						$error .= "Conditional forwarding domain name (\"".htmlspecialchars($domain)."\") is invalid!<br>";
					}

					if(!$error)
					{
						$extra .= " rev-server ".$cidr." ".$target." ".$domain;
					}
				}

				// Check if DNSinterface is set
				if(isset($_POST["DNSinterface"]))
				{
					if($_POST["DNSinterface"] === "single")
					{
						$DNSinterface = "single";
					}
					elseif($_POST["DNSinterface"] === "all")
					{
						$DNSinterface = "all";
					}
					else
					{
						$DNSinterface = "local";
					}
				}
				else
				{
					// Fallback
					$DNSinterface = "local";
				}
				pihole_execute("-a -i ".$DNSinterface." -web");

				// If there has been no error we can save the new DNS server IPs
				if(!strlen($error))
				{
					$IPs = implode (",", $DNSservers);
					$return = pihole_execute("-a setdns \"".$IPs."\" ".$extra);
					$success .= htmlspecialchars(end($return))."<br>";
					$success .= "The DNS settings have been updated (using ".$DNSservercount." DNS servers)";
				}
				else
				{
					$error .= "The settings have been reset to their previous values";
				}

				break;

			// Set query logging
			case "Logging":

				if($_POST["action"] === "Disable")
				{
					pihole_execute("-l off");
					$success .= "Logging has been disabled and logs have been flushed";
				}
				elseif($_POST["action"] === "Disable-noflush")
				{
					pihole_execute("-l off noflush");
					$success .= "Logging has been disabled, your logs have <strong>not</strong> been flushed";
				}
				else
				{
					pihole_execute("-l on");
					$success .= "Logging has been enabled";
				}

				break;

			// Set domains to be excluded from being shown in Top Domains (or Ads) and Top Clients
			case "API":

				// Explode the contents of the textareas into PHP arrays
				// \n (Unix) and \r\n (Win) will be considered as newline
				// array_filter( ... ) will remove any empty lines
				$domains = array_filter(preg_split('/\r\n|[\r\n]/', $_POST["domains"]));
				$clients = array_filter(preg_split('/\r\n|[\r\n]/', $_POST["clients"]));

				$domainlist = "";
				$first = true;
				foreach($domains as $domain)
				{
					if(!validDomainWildcard($domain) || validIP($domain))
					{
						$error .= "Top Domains/Ads entry ".htmlspecialchars($domain)." is invalid (use only domains)!<br>";
					}
					if(!$first)
					{
						$domainlist .= ",";
					}
					else
					{
						$first = false;
					}
					$domainlist .= $domain;
				}

				$clientlist = "";
				$first = true;
				foreach($clients as $client)
				{
					if(!validDomainWildcard($client) && !validIP($client))
					{
						$error .= "Top Clients entry ".htmlspecialchars($client)." is invalid (use only host names and IP addresses)!<br>";
					}
					if(!$first)
					{
						$clientlist .= ",";
					}
					else
					{
						$first = false;
					}
					$clientlist .= $client;
				}

				// Set Top Lists options
				if(!strlen($error))
				{
					// All entries are okay
					pihole_execute("-a setexcludedomains ".$domainlist);
					pihole_execute("-a setexcludeclients ".$clientlist);
					$success .= "The API settings have been updated<br>";
				}
				else
				{
					$error .= "The settings have been reset to their previous values";
				}

				// Set query log options
				if(isset($_POST["querylog-permitted"]) && isset($_POST["querylog-blocked"]))
				{
					pihole_execute("-a setquerylog all");
					if(!isset($_POST["privacyMode"]))
					{
						$success .= "All entries will be shown in Query Log";
					}
					else
					{
						$success .= "Only blocked entries will be shown in Query Log";
					}
				}
				elseif(isset($_POST["querylog-permitted"]))
				{
					pihole_execute("-a setquerylog permittedonly");
					if(!isset($_POST["privacyMode"]))
					{
						$success .= "Only permitted will be shown in Query Log";
					}
					else
					{
						$success .= "No entries will be shown in Query Log";
					}
				}
				elseif(isset($_POST["querylog-blocked"]))
				{
					pihole_execute("-a setquerylog blockedonly");
					$success .= "Only blocked entries will be shown in Query Log";
				}
				else
				{
					pihole_execute("-a setquerylog nothing");
					$success .= "No entries will be shown in Query Log";
				}


				if(isset($_POST["privacyMode"]))
				{
					pihole_execute("-a privacymode true");
					$success .= " (privacy mode enabled)";
				}
				else
				{
					pihole_execute("-a privacymode false");
				}

				break;

			case "webUI":
				$adminemail = trim($_POST["adminemail"]);
				if(strlen($adminemail) == 0 || !isset($adminemail))
				{
					$adminemail = '';
				}
				if(strlen($adminemail) > 0 && !validEmail($adminemail))
				{
					$error .= "Administrator email address (".htmlspecialchars($adminemail).") is invalid!<br>";
				}
				else
				{
					pihole_execute('-a -e \''.$adminemail.'\'');
				}
				if(isset($_POST["boxedlayout"]))
				{
					pihole_execute('-a layout boxed');
				}
				else
				{
					pihole_execute('-a layout traditional');
				}
				if(isset($_POST["webtheme"]))
				{
					global $available_themes;
					if(array_key_exists($_POST["webtheme"], $available_themes))
						exec('sudo pihole -a theme '.$_POST["webtheme"]);
				}
				$success .= "The webUI settings have been updated";
				break;

			case "poweroff":
				pihole_execute("-a poweroff");
				$success = "The system will poweroff in 5 seconds...";
				break;

			case "reboot":
				pihole_execute("-a reboot");
				$success = "The system will reboot in 5 seconds...";
				break;

			case "restartdns":
				pihole_execute("-a restartdns");
				$success = "The DNS server has been restarted";
				break;

			case "flushlogs":
				pihole_execute("-f");
				$success = "The Pi-hole log file has been flushed";
				break;

			case "DHCP":

				if(isset($_POST["addstatic"]))
				{
					$mac = trim($_POST["AddMAC"]);
					$ip = trim($_POST["AddIP"]);
					$hostname = trim($_POST["AddHostname"]);

					addStaticDHCPLease($mac, $ip, $hostname);
					break;
				}

				if(isset($_POST["removestatic"]))
				{
					$mac = $_POST["removestatic"];
					if(!validMAC($mac))
					{
						$error .= "MAC address (".htmlspecialchars($mac).") is invalid!<br>";
					}
					$mac = strtoupper($mac);

					if(!strlen($error))
					{
						pihole_execute("-a removestaticdhcp ".$mac);
						$success .= "The static address with MAC address ".htmlspecialchars($mac)." has been removed";
					}
					break;
				}

				if(isset($_POST["active"]))
				{
					// Validate from IP
					$from = $_POST["from"];
					if (!validIP($from))
					{
						$error .= "From IP (".htmlspecialchars($from).") is invalid!<br>";
					}

					// Validate to IP
					$to = $_POST["to"];
					if (!validIP($to))
					{
						$error .= "To IP (".htmlspecialchars($to).") is invalid!<br>";
					}

					// Validate router IP
					$router = $_POST["router"];
					if (!validIP($router))
					{
						$error .= "Router IP (".htmlspecialchars($router).") is invalid!<br>";
					}

					$domain = $_POST["domain"];

					// Validate Domain name
					if(!validDomain($domain))
					{
						$error .= "Domain name ".htmlspecialchars($domain)." is invalid!<br>";
					}

					$leasetime = $_POST["leasetime"];

					// Validate Lease time length
					if(!is_numeric($leasetime) || intval($leasetime) < 0)
					{
						$error .= "Lease time ".htmlspecialchars($leasetime)." is invalid!<br>";
					}

					if(isset($_POST["useIPv6"]))
					{
						$ipv6 = "true";
						$type = "(IPv4 + IPv6)";
					}
					else
					{
						$ipv6 = "false";
						$type = "(IPv4)";
					}

					if(isset($_POST["DHCP_rapid_commit"]))
					{
						$rapidcommit = "true";
					}
					else
					{
						$rapidcommit = "false";
					}

					if(!strlen($error))
					{
						pihole_execute("-a enabledhcp ".$from." ".$to." ".$router." ".$leasetime." ".$domain." ".$ipv6." ".$rapidcommit);
						$success .= "The DHCP server has been activated ".htmlspecialchars($type);
					}
				}
				else
				{
					pihole_execute("-a disabledhcp");
					$success = "The DHCP server has been deactivated";
				}

				break;

			case "privacyLevel":
				$level = intval($_POST["privacylevel"]);
				if($level >= 0 && $level <= 4)
				{
					// Check if privacylevel is already set
					if (isset($piholeFTLConf["PRIVACYLEVEL"])) {
						$privacylevel = intval($piholeFTLConf["PRIVACYLEVEL"]);
					} else {
						$privacylevel = 0;
					}

					// Store privacy level
					pihole_execute("-a privacylevel ".$level);

					if($privacylevel > $level)
					{
						pihole_execute("-a restartdns");
						$success .= "The privacy level has been decreased and the DNS resolver has been restarted";
					}
					elseif($privacylevel < $level)
					{
						$success .= "The privacy level has been increased";
					}
					else
					{
						$success .= "The privacy level has not been changed";
					}
				}
				else
				{
					$error .= "Invalid privacy level (".$level.")!";
				}
				break;
			// Flush network table
			case "flusharp":
				$output = pihole_execute("arpflush quiet");
				$error = "";
				if(is_array($output))
				{
					$error = implode("<br>", $output);
				}
				if(strlen($error) == 0)
				{
					$success .= "The network table has been flushed";
				}
				break;

			default:
				// Option not found
				$debug = true;
				break;
		}
	}

	// Credit: http://stackoverflow.com/a/5501447/2087442
	function formatSizeUnits($bytes)
	{
		if ($bytes >= 1073741824)
		{
			$bytes = number_format($bytes / 1073741824, 2) . ' GB';
		}
		elseif ($bytes >= 1048576)
		{
			$bytes = number_format($bytes / 1048576, 2) . ' MB';
		}
		elseif ($bytes >= 1024)
		{
			$bytes = number_format($bytes / 1024, 2) . ' kB';
		}
		elseif ($bytes > 1)
		{
			$bytes = $bytes . ' bytes';
		}
		elseif ($bytes == 1)
		{
			$bytes = $bytes . ' byte';
		}
		else
		{
			$bytes = '0 bytes';
		}

		return $bytes;
	}
?>
