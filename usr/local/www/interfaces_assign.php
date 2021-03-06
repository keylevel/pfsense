<?php 
/*
	interfaces_assign.php
	part of m0n0wall (http://m0n0.ch/wall)
	Written by Jim McBeath based on existing m0n0wall files
	
	Copyright (C) 2003-2005 Manuel Kasper <mk@neon1.net>.
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
*/
/*
	pfSense_BUILDER_BINARIES:	/bin/rm
	pfSense_MODULE:	interfaces
*/

##|+PRIV
##|*IDENT=page-interfaces-assignnetworkports
##|*NAME=Interfaces: Assign network ports page
##|*DESCR=Allow access to the 'Interfaces: Assign network ports' page.
##|*MATCH=interfaces_assign.php*
##|-PRIV

$pgtitle = array(gettext("Interfaces"),gettext("Assign network ports"));
$statusurl = "status_interfaces.php";

require("guiconfig.inc");
require("functions.inc");
require("filter.inc");
require("shaper.inc");
require("ipsec.inc");
require("vpn.inc");
require("captiveportal.inc");
require("rrd.inc");

/*
	In this file, "port" refers to the physical port name,
	while "interface" refers to LAN, WAN, or OPTn.
*/

/* get list without VLAN interfaces */
$portlist = get_interface_list();

/* add wireless clone interfaces */
if (is_array($config['wireless']['clone']) && count($config['wireless']['clone'])) {
	foreach ($config['wireless']['clone'] as $clone) {
		$portlist[$clone['cloneif']] = $clone;
		$portlist[$clone['cloneif']]['iswlclone'] = true;
	}
}

/* add VLAN interfaces */
if (is_array($config['vlans']['vlan']) && count($config['vlans']['vlan'])) {
	foreach ($config['vlans']['vlan'] as $vlan) {
		$portlist[$vlan['vlanif']] = $vlan;
		$portlist[$vlan['vlanif']]['isvlan'] = true;
	}
}

/* add Bridge interfaces */
if (is_array($config['bridges']['bridged']) && count($config['bridges']['bridged'])) {
        foreach ($config['bridges']['bridged'] as $bridge) {
                $portlist[$bridge['bridgeif']] = $bridge;
                $portlist[$bridge['bridgeif']]['isbridge'] = true;
        }
}

/* add GIF interfaces */
if (is_array($config['gifs']['gif']) && count($config['gifs']['gif'])) {
        foreach ($config['gifs']['gif'] as $gif) {
                $portlist[$gif['gifif']] = $gif;
                $portlist[$gif['gifif']]['isgif'] = true;
        }
}

/* add GRE interfaces */
if (is_array($config['gres']['gre']) && count($config['gres']['gre'])) {
        foreach ($config['gres']['gre'] as $gre) {
                $portlist[$gre['greif']] = $gre;
                $portlist[$gre['greif']]['isgre'] = true;
        }
}

/* add LAGG interfaces */
if (is_array($config['laggs']['lagg']) && count($config['laggs']['lagg'])) {
        foreach ($config['laggs']['lagg'] as $lagg) {
                $portlist[$lagg['laggif']] = $lagg;
                $portlist[$lagg['laggif']]['islagg'] = true;
		/* LAGG members cannot be assigned */
		$lagifs = explode(',', $lagg['members']);
		foreach ($lagifs as $lagif)
			if (isset($portlist[$lagif]))
				unset($portlist[$lagif]);
        }
}

/* add QinQ interfaces */
if (is_array($config['qinqs']['qinqentry']) && count($config['qinqs']['qinqentry'])) {
        foreach ($config['qinqs']['qinqentry'] as $qinq) {
                $portlist["vlan{$qinq['tag']}"]['descr'] = "VLAN {$qinq['tag']}";
                $portlist["vlan{$qinq['tag']}"]['isqinq'] = true;
                /* QinQ members */
                $qinqifs = explode(' ', $qinq['members']);
                foreach ($qinqifs as $qinqif) {
			$portlist["vlan{$qinq['tag']}_{$qinqif}"]['descr'] = "QinQ {$qinqif}";
			$portlist["vlan{$qinq['tag']}_{$qinqif}"]['isqinq'] = true;
		}
        }
}

/* add PPP interfaces */
if (is_array($config['ppps']['ppp']) && count($config['ppps']['ppp'])) {
	foreach ($config['ppps']['ppp'] as $pppid => $ppp) {
		$portname = $ppp['if'];
		$portlist[$portname] = $ppp;
		$portlist[$portname]['isppp'] = true;
		$ports_base = basename($ppp['ports']);
		if (isset($ppp['descr']))
			$portlist[$portname]['descr'] = strtoupper($ppp['if']). "({$ports_base}) - {$ppp['descr']}";
		else if (isset($ppp['username']))
			$portlist[$portname]['descr'] = strtoupper($ppp['if']). "({$ports_base}) - {$ppp['username']}";
		else
			$portlist[$portname]['descr'] = strtoupper($ppp['if']). "({$ports_base})";
	}
}

$ovpn_descrs = array();
if (is_array($config['openvpn'])) {
	if (is_array($config['openvpn']['openvpn-server']))
		foreach ($config['openvpn']['openvpn-server'] as $s)
			$ovpn_descrs[$s['vpnid']] = $s['description'];
	if (is_array($config['openvpn']['openvpn-client']))
		foreach ($config['openvpn']['openvpn-client'] as $c)
			$ovpn_descrs[$c['vpnid']] = $c['description'];
}

if ($_POST['apply']) {
	if (file_exists("/var/run/interface_mismatch_reboot_needed"))
		system_reboot();
	else {
		write_config();

		$retval = 0;
		$retval = filter_configure();
		$savemsg = get_std_save_message($retval);

		if (stristr($retval, "error") <> true)
			$savemsg = get_std_save_message($retval);
		else
			$savemsg = $retval;
	}

} else if ($_POST) {

	unset($input_errors);

	/* input validation */

	/* Build a list of the port names so we can see how the interfaces map */
	$portifmap = array();
	foreach ($portlist as $portname => $portinfo)
		$portifmap[$portname] = array();

	/* Go through the list of ports selected by the user,
	   build a list of port-to-interface mappings in portifmap */
	foreach ($_POST as $ifname => $ifport) {
		if (($ifname == 'lan') || ($ifname == 'wan') || (substr($ifname, 0, 3) == 'opt'))
			$portifmap[$ifport][] = strtoupper($ifname);
	}

	/* Deliver error message for any port with more than one assignment */
	foreach ($portifmap as $portname => $ifnames) {
		if (count($ifnames) > 1) {
			$errstr = sprintf(gettext('Port %1$s '.
				' was assigned to %2$s' .
				' interfaces:'), $portname, count($ifnames));
				
			foreach ($portifmap[$portname] as $ifn)
				$errstr .= " " . $ifn;
			
			$input_errors[] = $errstr;
		}
	}

	if (is_array($config['vlans']['vlan'])) {
		foreach ($config['vlans']['vlan'] as $vlan) {
			if (does_interface_exist($vlan['if']) == false)
				$input_errors[] = "Vlan parent interface {$vlan['if']} does not exist anymore so vlan id {$vlan['tag']} cannot be created please fix the issue before continuing.";
		}
	}

	if (!$input_errors) {
		/* No errors detected, so update the config */
		foreach ($_POST as $ifname => $ifport) {
		
			if (($ifname == 'lan') || ($ifname == 'wan') ||
				(substr($ifname, 0, 3) == 'opt')) {
				
				if (!is_array($ifport)) {
					$reloadif = false;
					if (!empty($config['interfaces'][$ifname]['if']) && $config['interfaces'][$ifname]['if'] <> $ifport) {
						interface_bring_down($ifname);
						/* Mark this to be reconfigured in any case. */
						$reloadif = true;
					}
					$config['interfaces'][$ifname]['if'] = $ifport;
					if (isset($portlist[$ifport]['isppp']))
						$config['interfaces'][$ifname]['ipaddr'] = $portlist[$ifport]['type'];
					
					/* check for wireless interfaces, set or clear ['wireless'] */
					if (preg_match($g['wireless_regex'], $ifport)) {
						if (!is_array($config['interfaces'][$ifname]['wireless']))
							$config['interfaces'][$ifname]['wireless'] = array();
					} else {
						unset($config['interfaces'][$ifname]['wireless']);
					}
					
					/* make sure there is a descr for all interfaces */
					if (!isset($config['interfaces'][$ifname]['descr']))
						$config['interfaces'][$ifname]['descr'] = strtoupper($ifname);
						
					if ($reloadif == true) {
						if (preg_match($g['wireless_regex'], $ifport))
							interface_sync_wireless_clones($config['interfaces'][$ifname], false);
						/* Reload all for the interface. */
						interface_configure($ifname, true);
					}
				}
			}
		}
	
		write_config();
		
		enable_rrd_graphing();
	}
}

if ($_GET['act'] == "del") {
	$id = $_GET['id'];

	if (link_interface_to_group($id))
		$input_errors[] = gettext("The interface is part of a group. Please remove it from the group to continue");
	else if (link_interface_to_bridge($id))
		$input_errors[] = gettext("The interface is part of a bridge. Please remove it from the bridge to continue");
	else if (link_interface_to_gre($id))
		$input_errors[] = gettext("The interface is part of a gre tunnel. Please delete the tunnel to continue");
	else if (link_interface_to_gif($id))
		$input_errors[] = gettext("The interface is part of a gif tunnel. Please delete the tunnel to continue");
	else {
		unset($config['interfaces'][$id]['enable']);
		$realid = get_real_interface($id);
		interface_bring_down($id);   /* down the interface */
		
		unset($config['interfaces'][$id]);	/* delete the specified OPTn or LAN*/

		if($id == "lan") {
			unset($config['interfaces']['lan']);
			if (is_array($config['dhcpd']))
				unset($config['dhcpd']['lan']);
				unset($config['shaper']);
				unset($config['ezshaper']);
				unset($config['nat']);
				system("rm /var/dhcpd/var/db/*");
        			services_dhcpd_configure();
		}

		if (count($config['filter']['rule']) > 0) {
			foreach ($config['filter']['rule'] as $x => $rule) {
				if($rule['interface'] == $id)
					unset($config['filter']['rule'][$x]);
			}
        	}
		if (is_array($config['nat']['advancedoutbound']) && count($config['nat']['advancedoutbound']['rule']) > 0) {
        	foreach ($config['nat']['advancedoutbound']['rule'] as $x => $rule) {
				if($rule['interface'] == $id)
					unset($config['nat']['advancedoutbound']['rule'][$x]['interface']);
        		}
		}
		if (is_array($config['nat']['rule']) && count($config['nat']['rule']) > 0) {
			foreach ($config['nat']['rule'] as $x => $rule) {
				if($rule['interface'] == $id)
					unset($config['nat']['rule'][$x]['interface']);
			}
        }

		write_config();
	
		/* If we are in firewall/routing mode (not single interface)
		 * then ensure that we are not running DHCP on the wan which
		 * will make a lot of ISP's unhappy.
		 */
		if($config['interfaces']['lan'] && $config['dhcpd']['wan']) {
			unset($config['dhcpd']['wan']);		
		}

		link_interface_to_vlans($realid, "update");
	
		$savemsg = gettext("Interface has been deleted.");
	}
}

if ($_GET['act'] == "add" && (count($config['interfaces']) < count($portlist))) {
	/* find next free optional interface number */
	if(!$config['interfaces']['lan']) {
		$newifname = gettext("lan");
		$descr = gettext("LAN");
		$config['interfaces'][$newifname] = array();
		$config['interfaces'][$newifname]['descr'] = $descr;
	} else {
		for ($i = 1; $i <= count($config['interfaces']); $i++) {
			if (!$config['interfaces']["opt{$i}"])
				break;
		}
		$newifname = 'opt' . $i;
		$descr = "OPT" . $i;
		$config['interfaces'][$newifname] = array();
		$config['interfaces'][$newifname]['descr'] = $descr;
	}

	uksort($config['interfaces'], "compare_interface_friendly_names");

	/* Find an unused port for this interface */
	foreach ($portlist as $portname => $portinfo) {
		$portused = false;
		foreach ($config['interfaces'] as $ifname => $ifdata) {
			if ($ifdata['if'] == $portname) {
				$portused = true;
				break;
			}
		}
		if (!$portused) {
			$config['interfaces'][$newifname]['if'] = $portname;
			if (preg_match($g['wireless_regex'], $portname)) {
				$config['interfaces'][$newifname]['wireless'] = array();
				interface_sync_wireless_clones($config['interfaces'][$newifname], false);
			}
			break;
		}
	}
	
        /* XXX: Do not remove this. */
        mwexec("/bin/rm -f /tmp/config.cache");

	write_config();

	$savemsg = gettext("Interface has been added.");

} else if ($_GET['act'] == "add")
	$input_errors[] = "No more interfaces available to be assigned.";

include("head.inc");

if(file_exists("/var/run/interface_mismatch_reboot_needed")) 
	if ($_POST)
		$savemsg = gettext("Reboot is needed. Please apply the settings in order to reboot.");
	else
		$savemsg = gettext("Interface mismatch detected.  Please resolve the mismatch and click Save.  The firewall will reboot afterwards.");

?>

<body link="#0000CC" vlink="#0000CC" alink="#0000CC">
<?php include("fbegin.inc"); ?>

<form action="interfaces_assign.php" method="post" name="iform" id="iform">

<?php if (file_exists("/tmp/reload_interfaces")): ?><p>
	<?php print_info_box_np(gettext("The interface configuration has been changed.<br>You must apply the changes in order for them to take effect."));?><br>
<?php elseif($savemsg): ?>
	<?php print_info_box($savemsg); ?>
<?php endif; ?>

<?php if ($input_errors) print_input_errors($input_errors); ?>

<table width="100%" border="0" cellpadding="0" cellspacing="0">
  <tr><td class="tabnavtbl">
<?php
	$tab_array = array();
	$tab_array[0] = array(gettext("Interface assignments"), true, "interfaces_assign.php");
	$tab_array[1] = array(gettext("Interface Groups"), false, "interfaces_groups.php");
	$tab_array[2] = array(gettext("Wireless"), false, "interfaces_wireless.php");
	$tab_array[3] = array(gettext("VLANs"), false, "interfaces_vlan.php");
	$tab_array[4] = array(gettext("QinQs"), false, "interfaces_qinq.php");
	$tab_array[5] = array(gettext("PPPs"), false, "interfaces_ppps.php");
	$tab_array[7] = array(gettext("GRE"), false, "interfaces_gre.php");
	$tab_array[8] = array(gettext("GIF"), false, "interfaces_gif.php");
	$tab_array[9] = array(gettext("Bridges"), false, "interfaces_bridge.php");
	$tab_array[10] = array(gettext("LAGG"), false, "interfaces_lagg.php");
	display_top_tabs($tab_array);
?>  
  </td></tr>
  <tr> 
    <td>
	<div id="mainarea">
        <table class="tabcont" width="100%" border="0" cellpadding="0" cellspacing="0">
       <tr> 
	<td class="listhdrr"><?=gettext("Interface"); ?></td>
	<td class="listhdr"><?=gettext("Network port"); ?></td>
	<td class="list">&nbsp;</td>
  </tr>
  <?php foreach ($config['interfaces'] as $ifname => $iface):
  	if ($iface['descr'])
		$ifdescr = $iface['descr'];
	else
		$ifdescr = strtoupper($ifname);
	?>
  <tr> 
	<td class="listlr" valign="middle"><strong><?=$ifdescr;?></strong></td>
	  <td valign="middle" class="listr">
		<select onChange="javascript:$('savediv').appear();" name="<?=$ifname;?>" id="<?=$ifname;?>">
		  <?php foreach ($portlist as $portname => $portinfo): ?>
			<option  value="<?=$portname;?>"  <?php if ($portname == $iface['if']) echo " selected";?>>
				<?php if ($portinfo['isvlan']) {
					$descr = sprintf(gettext('VLAN %1$s on %2$s'),$portinfo['tag'],$portinfo['if']);
				if ($portinfo['descr'])
					$descr .= " (" . $portinfo['descr'] . ")";
					echo htmlspecialchars($descr);
				} elseif ($portinfo['iswlclone']) {
					$descr = $portinfo['cloneif'];
					if ($portinfo['descr'])
						$descr .= " (" . $portinfo['descr'] . ")";
					echo htmlspecialchars($descr);
				} elseif ($portinfo['isppp']) {
					echo htmlspecialchars($portinfo['descr']);
				} elseif ($portinfo['isbridge']) {
					$descr = strtoupper($portinfo['bridgeif']);
					if ($portinfo['descr'])
						$descr .= " (" . $portinfo['descr'] . ")";
					echo htmlspecialchars($descr);
				} elseif ($portinfo['isgre']) {
					$descr = "GRE {$portinfo['remote-addr']}";
					if ($portinfo['descr'])
						$descr .= " (" . $portinfo['descr'] . ")";
					echo htmlspecialchars($descr);
				} elseif ($portinfo['isgif']) {
					$descr = "GIF {$portinfo['remote-addr']}";
					if ($portinfo['descr'])
						$descr .= " (" . $portinfo['descr'] . ")";
					echo htmlspecialchars($descr);
				} elseif ($portinfo['islagg']) {
					$descr = strtoupper($portinfo['laggif']);
					if ($portinfo['descr'])
						$descr .= " (" . $portinfo['descr'] . ")";
					echo htmlspecialchars($descr);
				} elseif ($portinfo['isqinq']) {
					echo htmlspecialchars($portinfo['descr']);
				} elseif (substr($portname, 0, 4) == 'ovpn') {
					echo htmlspecialchars($portname . " (" . $ovpn_descrs[substr($portname, 5, 1)] . ")");
				} else
					echo htmlspecialchars($portname . " (" . $portinfo['mac'] . ")");
			?></option>
		<?php endforeach; ?>
	</select>
	</td>
	<td valign="middle" class="list">
		  <?php if ($ifname != 'wan'): ?>
		  <a href="interfaces_assign.php?act=del&id=<?=$ifname;?>" onclick="return confirm('<?=gettext("Do you really want to delete this interface?");?>')"><img src="./themes/<?= $g['theme']; ?>/images/icons/icon_x.gif" title=<?=gettext("delete interface"); ?> width="17" height="17" border="0"></a> 
		  <?php endif; ?>
		</td>
  </tr>
  <?php endforeach; ?>
  <?php if (count($config['interfaces']) < count($portlist)): ?>
  <tr>
	<td class="list" colspan="2"></td>
	<td class="list" nowrap>
	<a href="interfaces_assign.php?act=add"><img src="./themes/<?= $g['theme']; ?>/images/icons/icon_plus.gif" title=<?=gettext("add interface"); ?> width="17" height="17" border="0"></a>
	</td>
  </tr>
  <?php else: ?>
  <tr>
	<td class="list" colspan="3" height="10"></td>
  </tr>
  <?php endif; ?>
</table>
</div>
<br/>
<div name='savediv' id='savediv' <?php if (empty($_GET['act'])) echo "style='display:none;'"; ?>>
	<input name="Submit" type="submit" class="formbtn" value="<?=gettext("Save"); ?>"><br><br>
	<p>
</div>
</p>
<ul>
	<li><span class="vexpl"><?=gettext("Interfaces that are configured as members of a lagg(4) interface will not be shown."); ?></span></li>
</ul>
</td>
</tr>
</table>
</form>
<?php include("fend.inc"); ?>
</body>
</html>
