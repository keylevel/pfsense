<?php
/*
  Copyright (C) 2008 Bill Marquette, Seth Mos
  Copyright (C) 2010 Ermal Lu�i
  All rights reserved.

  Redistribution and use in source and binary forms, with or without
  modification, are permitted provided that the following conditions are met:

  1. Redistributions of source code must retain the above copyright notice,
  this list of conditions and the following disclaimer.

  2. Redistributions in binary form must reproduce the above copyright
  notice, this list of conditions and the following disclaimer in the
  documentation and/or other materials provided with the distribution.

  THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
  INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
  AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
  AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
  OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
  SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
  INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
  CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
  ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
  POSSIBILITY OF SUCH DAMAGE.

	pfSense_BUILDER_BINARIES:	/usr/bin/killall	/sbin/route	/usr/local/sbin/apinger
	pfSense_MODULE:	routing

 */

/*
 * Creates monitoring configuration file and
 * adds apropriate static routes.
 */
function setup_gateways_monitor() {
	global $config, $g;

	$gateways_arr = return_gateways_array();
	if (!is_array($gateways_arr)) {
		log_error("No gateways to monitor. Apinger will not be run.");
		killbypid("{$g['varrun_path']}/apinger.pid");
		@unlink("{$g['tmp_path']}/apinger.status");
		return;
	}

	/* Default settings. Probably should move to globals.inc? */
	$a_settings = array();
	$a_settings['latencylow'] = "200";
	$a_settings['latencyhigh'] = "500";
	$a_settings['losslow'] = "10";
	$a_settings['losshigh'] = "20";

	$fd = fopen("{$g['varetc_path']}/apinger.conf", "w");
	$apingerconfig = <<<EOD

# pfSense apinger configuration file. Automatically Generated!

## User and group the pinger should run as
user "root"
group "wheel"

## Mailer to use (default: "/usr/lib/sendmail -t")
#mailer "/var/qmail/bin/qmail-inject" 

## Location of the pid-file (default: "/var/run/apinger.pid")
pid_file "{$g['varrun_path']}/apinger.pid"

## Format of timestamp (%s macro) (default: "%b %d %H:%M:%S")
#timestamp_format "%Y%m%d%H%M%S"

status {
	## File where the status information whould be written to
	file "{$g['tmp_path']}/apinger.status"
	## Interval between file updates
	## when 0 or not set, file is written only when SIGUSR1 is received
	interval 5s
}

########################################
# RRDTool status gathering configuration
# Interval between RRD updates
rrd interval 60s;

## These parameters can be overriden in a specific alarm configuration
alarm default { 
	command on "/usr/local/sbin/pfSctl -c 'filter reload'"
	command off "/usr/local/sbin/pfSctl -c 'filter reload'"
	combine 10s
}

## "Down" alarm definition. 
## This alarm will be fired when target doesn't respond for 30 seconds.
alarm down "down" {
	time 10s
}

## "Delay" alarm definition. 
## This alarm will be fired when responses are delayed more than 200ms
## it will be canceled, when the delay drops below 100ms
alarm delay "delay" {
	delay_low {$a_settings['latencylow']}ms
	delay_high {$a_settings['latencyhigh']}ms
}

## "Loss" alarm definition. 
## This alarm will be fired when packet loss goes over 20%
## it will be canceled, when the loss drops below 10%
alarm loss "loss" {
	percent_low {$a_settings['losslow']}
	percent_high {$a_settings['losshigh']}
}

target default {
	## How often the probe should be sent	
	interval 1s
	
	## How many replies should be used to compute average delay 
	## for controlling "delay" alarms
	avg_delay_samples 10
	
	## How many probes should be used to compute average loss
	avg_loss_samples 50

	## The delay (in samples) after which loss is computed
	## without this delays larger than interval would be treated as loss
	avg_loss_delay_samples 20

	## Names of the alarms that may be generated for the target
	alarms "down","delay","loss"

	## Location of the RRD
	#rrd file "{$g['vardb_path']}/rrd/apinger-%t.rrd"
}

EOD;

	foreach($gateways_arr as $name => $gateway) {
		if (empty($gateway['monitor']) || !is_ipaddr($gateway['monitor'])) {
			if (is_ipaddr($gateway['gateway']))
				$gateway['monitor'] = $gateway['gateway'];
			else /* No chance to get an ip to monitor skip target. */
				continue;
		}

		/* Interface ip is needed since apinger will bind a socket to it. */
		$gwifip = find_interface_ip($gateway['interface'], true);
		if (!is_ipaddr($gwifip))
			continue; //Skip this target

		$apingercfg = "target \"{$gateway['monitor']}\" {\n";
		$apingercfg .= "	description \"{$name}\"\n";
		$apingercfg .= "	srcip \"{$gwifip}\"\n";
		$alarms = "";
		$alarmscfg = "";
		$override = false;
		if (!empty($gateway['lowloss'])) {
			$alarmscfg .= "alarm loss \"{$name}loss\" {\n";
			$alarmscfg .= "\tpercent_low {$gateway['losslow']}\n";
       			$alarmscfg .= "\tpercent_high {$gateway['losshigh']}\n";
			$alarmscfg .= "}\n";
			$alarms .= "\"{$name}loss\"";
			$override = true;
		} else {
			if ($override == true)
				$alarms .= ",";
			$alarms .= "\"loss\"";
			$override = true;
		}
		if (!empty($gateway['latencylow'])) {
			$alarmscfg .= "alarm delay \"{$name}delay\" {\n";
			$alarmscfg .= "\tdelay_low {$gateway['latencylow']}ms\n";
			$alarmscfg .= "\tdelay_high {$gateway['latencyhigh']}ms\n";
			$alarmscfg .= "}\n";
			if ($override == true)
				$alarms .= ",";
			$alarms .= "\"{$name}delay\"";
			$override = true;
		} else {
			if ($override == true)
				$alarms .= ",";
			$alarms .= "\"delay\"";
			$override = true;
		}
		if (!empty($gateway['down'])) {
			$alarmscfg .= "alarm down \"{$name}down\" {\n";
			$alarmscfg .= "\ttime {$gateway['down']}s\n";
			$alarmscfg .= "}\n";
			if ($override == true)
				$alarms .= ",";
			$alarms .= "\"{$name}down\"";
			$override = true;
		} else {
			if ($override == true)
				$alarms .= ",";
			$alarms .= "\"down\"";
			$override = true;
		}
		if ($override == true)
			$apingercfg .= "\talarms override {$alarms};\n";

		$apingercfg .= "	rrd file \"{$g['vardb_path']}/rrd/{$gateway['name']}-quality.rrd\"\n";
		$apingercfg .= "}\n";
		$apingercfg .= "\n";
		/*
		 * If the gateway is the same as the monitor we do not add a
		 * route as this will break the routing table.
		 * Add static routes for each gateway with their monitor IP
		 * not strictly necessary but is a added level of protection.
		 */
		if (is_ipaddr($gateway['gateway']) && $gateway['monitor'] != $gateway['gateway']) {
			log_error("Removing static route for monitor {$gateway['monitor']} and adding a new route through {$gateway['gateway']}");
			mwexec("/sbin/route delete -host " . escapeshellarg($gateway['monitor']), true);
			mwexec("/sbin/route add -host " . escapeshellarg($gateway['monitor']) .
				" " . escapeshellarg($gateway['gateway']), true);
		}

		$apingerconfig .= $alarmscfg;
		$apingerconfig .= $apingercfg;
	}
	fwrite($fd, $apingerconfig);
	fclose($fd);

	killbypid("{$g['varrun_path']}/apinger.pid");
	if (is_dir("{$g['tmp_path']}"))
		chmod("{$g['tmp_path']}", 01777);
	if (!is_dir("{$g['vardb_path']}/rrd"))
		mkdir("{$g['vardb_path']}/rrd", 0775);

	@chown("{$g['vardb_path']}/rrd", "nobody");

	/* start a new apinger process */
	@unlink("{$g['tmp_path']}/apinger.status");
	sleep(1);
	mwexec_bg("/usr/local/sbin/apinger -c {$g['varetc_path']}/apinger.conf");

	return 0;
}

/* return the status of the apinger targets as a array */
function return_gateways_status($byname = false) {
	global $config, $g;

	$apingerstatus = array();
	if (file_exists("{$g['tmp_path']}/apinger.status")) {
		$apingerstatus = file("{$g['tmp_path']}/apinger.status");
	}

	$status = array();
	foreach($apingerstatus as $line) {
		$info = explode("|", $line);
		if ($byname == false)
			$target = $info[0];
		else
			$target = $info[2];
		$status[$target]['monitorip'] = $info[0];
		$status[$target]['srcip'] = $info[1];
		$status[$target]['name'] = $info[2];
		$status[$target]['lastcheck'] = $info[5] ? date('r', $info[5]) : date('r');
		$status[$target]['delay'] = empty($info[6]) ? 0 : $info[6];
		$status[$target]['loss'] = empty($info[7]) ? "0.0%" : $info[7] . "";
		$status[$target]['status'] = trim($info[8]);
	}

	return($status);
}

/* Return all configured gateways on the system */
function return_gateways_array($disabled = false) {
	global $config, $g;

	$gateways_arr = array();

	$i = 0;
	/* Process/add all the configured gateways. */
	if (is_array($config['gateways']['gateway_item'])) {
		foreach($config['gateways']['gateway_item'] as $gateway) {
			if(empty($gateway['gateway']) || $gateway['gateway'] == "dynamic") {
				$gateway['gateway'] = get_interface_gateway($gateway['interface']);
				/* no IP address found, set to dynamic */
				if(! is_ipaddr($gateway['gateway']))
					$gateway['gateway'] = "dynamic";
				$gateway['dynamic'] = true;
			}
			if(empty($gateway['monitor']))
				$gateway['monitor'] = $gateway['gateway'];

			$gateway['friendlyiface'] = $gateway['interface'];
			$gateway['interface'] = get_real_interface($gateway['interface']);
			/* FIXME: Should this be enabled.
			 * Some interface like wan might be default but have no info recorded 
			 * the config.
			if ($gateway['friendlyiface'] == "wan" && !isset($gateway['defaultgw'])) {
				if (file_exists("{$g['tmp_path']}/{$gateway['interface']}_defaultgw"))
					$gateway['defaultgw'] = true;
			}
			 */
			/* include the gateway index as the attribute */
			$gateway['attribute'] = $i;

			$gateways_arr[$gateway['name']] = $gateway;
			$i++;
		}
	} 

	/* Loop through all interfaces with a gateway and add it to a array */
	if ($disabled == false)
		$iflist = get_configured_interface_with_descr();
	else
		$iflist = get_configured_interface_with_descr(false, true);

	/* Process/add dynamic gateways. */
	foreach($iflist as $ifname => $friendly ) {
		if(! interface_has_gateway($ifname))
			continue;

		if (empty($config['interfaces'][$ifname]))
			continue;

		$ifcfg = &$config['interfaces'][$ifname];
		if (!empty($ifcfg['ipaddr']) && is_ipaddr($ifcfg['ipaddr']))
			continue;

		$gateway = array();
		$gateway['dynamic'] = false;
		$gateway['gateway'] = get_interface_gateway($ifname, $gateway['dynamic']);
		$gateway['interface'] = get_real_interface($ifname);
		$gateway['friendlyiface'] = $ifname;
		$gateway['name'] = $friendly;
		$gateway['attribute'] = "system";
	
		if ($gateway['dynamic'] === "default") {
			$gateway['defaultgw'] = true;
			$gateway['dynamic'] = true;
		}
		/* Loopback dummy for dynamic interfaces without a IP */
		if (!is_ipaddr($gateway['gateway']) && $gateway['dynamic'] == true)
			$gateway['gateway'] = "dynamic";

		/* automatically skip known static and dynamic gateways we have a array entry for */
		foreach($gateways_arr as $gateway_item) {
			if (($ifname == $gateway_item['friendlyiface'] && $friendly == $gateway_item['name']) ||
				($ifname == $gateway_item['friendlyiface'] && $gateway_item['dynamic'] == true))
					continue 2;
		}

		if (is_ipaddr($gateway['gateway']))
			$gateway['monitor'] = $gateway['gateway'];

		$gateway['descr'] = "Interface {$friendly} Dynamic Gateway";
		$gateways_arr[$friendly] = $gateway;
	}

	return($gateways_arr);
}

/*
 * Return an array with all gateway groups with name as key
 * All gateway groups will be processed before returning the array.
 */
function return_gateway_groups_array() {
	global $config, $g;

	/* fetch the current gateways status */
	$gateways_status = return_gateways_status(true);
	$gateways_arr = return_gateways_array();
	$gateway_groups_array = array();

	if (isset($config['system']['gw_switch_default'])) {
	/* 
	 * NOTE: The code below is meant to replace the default gateway when it goes down.
	 *	This facilitates services running on pfSense itself and are not handled by a PBR to continue working.
	 */
	$upgw = "";
	$dfltgwdown = false;
	$dfltgwfound = false;
	foreach ($gateways_arr as $gwname => $gwsttng) {
		if (isset($gwsttng['defaultgw'])) {
			$dfltgwfound = true;
			if (stristr($gateways_status[$gwname]['status'], "down"))
				$dfltgwdown = true;
		}
		/* Keep a record of the last up gateway */
		if (empty($upgw) && !stristr($gateways_status[$gwname]['status'], "down"))
			$upgw = $gwname;
		if ($dfltgwdown == true && !empty($upgw))
			break;
	}
	if ($dfltgwfound == false) {
		$gwname = convert_friendly_interface_to_friendly_descr("wan");
		if (stristr($gateways_status[$gwname]['status'], "down"))
			$dfltgwdown = true;
	}
	if ($dfltgwdown == true && !empty($upgw)) {
		if ($gateways_arr[$upgw]['gateway'] == "dynamic")
			$gateways_arr[$upgw]['gateway'] = get_interface_gateway($gateways_arr[$upgw]['friendlyiface']);
		if (is_ipaddr($gateways_arr[$upgw]['gateway'])) {
			log_error("Default gateway down setting {$upgw} as default!");
			mwexec("/sbin/route delete -inet default; /sbin/route add -inet default {$gateways_arr[$upgw]['gateway']}");
		}
	}
	unset($upgw, $dfltgwfound, $dfltgwdown, $gwname, $gwsttng);
	}

	if (is_array($config['gateways']['gateway_group'])) {
		foreach($config['gateways']['gateway_group'] as $group) {
			/* create array with group gateways members seperated by tier */
			$tiers = array();
			$backupplan = array();
			foreach($group['item'] as $item) {
				$itemsplit = explode("|", $item);
				$tier = $itemsplit[1];
				$gwname = $itemsplit[0];

				/* Do it here rather than reiterating again the group in case no member is up. */
				$backupplan[$tier][] = $gwname;

				/* check if the gateway is available before adding it to the array */
				if (!empty($gateways_status[$gwname])) {
					$status = $gateways_status[$gwname];
					$gwdown = false;
					if (stristr($status['status'], "down")) {
						$msg = "MONITOR: {$gwname} is down, removing from routing group";
						$gwdown = true;
					} else if (stristr($status['status'], "loss") && strstr($group['trigger'], "loss")) {
						/* packet loss */
						$msg = "MONITOR: {$gwname} has packet loss, removing from routing group";
						$gwdown = true;
					} else if (stristr($status['status'], "delay") && strstr($group['trigger'] , "latency")) {
						/* high latency */
						$msg = "MONITOR: {$gwname} has high latency, removing from routing group";
						$gwdown = true;
					}
					if ($gwdown == true) {
						log_error($msg);
						notify_via_growl($msg);
						notify_via_smtp($msg);
					} else
						/* Online add member */
						$tiers[$tier][] = $gwname;
				}
			}
			$tiers_count = count($tiers);
			if($tiers_count == 0) {
				/* Oh dear, we have no members! Engage Plan B */
				if (!$g['booting']) {
					$msg = "Gateways status could not be determined, considering all as up/active.";
					log_error($msg);
					notify_via_growl($msg);
					notify_via_smtp($msg);
				}
				$tiers = $backupplan;
			}
			/* sort the tiers array by the tier key */
			ksort($tiers);

			/* we do not really foreach the tiers as we stop after the first tier */
			foreach($tiers as $tier) {
				/* process all gateways in this tier */
				foreach($tier as $member) {
					/* determine interface gateway */
					if (isset($gateways_arr[$member])) {
						$gateway = $gateways_arr[$member];
						$int = $gateway['interface'];
						$gatewayip = "";
						if(is_ipaddr($gateway['gateway'])) 
							$gatewayip = $gateway['gateway'];
						else if ($int <> "")
							$gatewayip = get_interface_gateway($gateway['friendlyiface']);
					
						if (($int <> "") && is_ipaddr($gatewayip)) {
							$groupmember = array();
							$groupmember['int']  = $int;
							$groupmember['gwip']  = $gatewayip;
							$groupmember['weight']  = isset($gateway['weight']) ? $gateway['weight'] : 1;
							$gateway_groups_array[$group['name']][] = $groupmember;
						}
					}
				}
				/* we should have the 1st available tier now, exit stage left */
				break;
			}
		}
	}
	return ($gateway_groups_array);
}

/* Update DHCP WAN Interface ip address in gateway group item */
function dhclient_update_gateway_groups_defaultroute($interface = "wan") {
	global $config, $g;
	foreach($config['gateways']['gateway_item'] as & $gw) {	
		if($gw['interface'] == $interface) {
			$current_gw = get_interface_gateway($interface);
			if($gw['gateway'] <> $current_gw) {
				$gw['gateway'] = $current_gw;
				$changed = true;
			}
		}
	}
	if($changed && $current_gw)
		write_config("Updating gateway group gateway for $interface - new gateway is $current_gw");
}

function lookup_gateway_ip_by_name($name) {

	$gateways_arr = return_gateways_array();
        foreach ($gateways_arr as $gname => $gw) {
                if ($gw['name'] == $name || $gname == $name)
                        return $gw['gateway'];
        }

	return false;
}

function lookup_gateway_monitor_ip_by_name($name) {

        $gateways_arr = return_gateways_array();
	if (!empty($gateways_arr[$name])) {
		$gateway = $gateways_arr[$name];
		if(!is_ipaddr($gateway['monitor']))
			return $gateway['gateway'];

		return $gateway['monitor'];
        }

        return (false);
}

function lookup_gateway_interface_by_name($name) {

        $gateways_arr = return_gateways_array();
	if (!empty($gateways_arr[$name])) {
		$interfacegw = $gateway['interface'];
		return ($interfacegw);
        }

        return (false);
}

function get_interface_gateway($interface, &$dynamic = false) {
        global $config, $g;

        $gw = NULL;

        $gwcfg = $config['interfaces'][$interface];
        if (!empty($gwcfg['gateway']) && is_array($config['gateways']['gateway_item'])) {
               	foreach($config['gateways']['gateway_item'] as $gateway) {
                        if ($gateway['name'] == $gwcfg['gateway']) {
                                $gw = $gateway['gateway'];
				break;
			}
                }
	}

        // for dynamic interfaces we handle them through the $interface_router file.
        if (!is_ipaddr($gw) && !is_ipaddr($gwcfg['ipaddr'])) {
                $realif = get_real_interface($interface);
                if (file_exists("{$g['tmp_path']}/{$realif}_router")) {
                        $gw = trim(file_get_contents("{$g['tmp_path']}/{$realif}_router"), " \n");
					$dynamic = true;
                }
                if (file_exists("{$g['tmp_path']}/{$realif}_defaultgw"))
					$dynamic = "default";
			
				
        }

        /* return gateway */
        return ($gw);
}

?>
