SET @entity_id = 0;

-- Basic reference data with high IDs to avoid conflicts
INSERT IGNORE INTO glpi_manufacturers (id, name) VALUES (200, 'Dell Inc');
INSERT IGNORE INTO glpi_computermodels (id, name) VALUES (200, 'OptiPlex 9020');
INSERT IGNORE INTO glpi_computertypes (id, name) VALUES (200, 'Desktop');
INSERT IGNORE INTO glpi_states (
    id, name, entities_id, is_recursive, states_id, completename, level, is_helpdesk_visible, date_creation, date_mod
) VALUES
(200, 'In production', @entity_id, 0, 0, 'In production', 1, 1, NOW(), NOW()),
(201, 'Maintenance', @entity_id, 0, 0, 'Maintenance', 1, 1, NOW(), NOW()),
(202, 'Spare', @entity_id, 0, 0, 'Spare', 1, 1, NOW(), NOW());

UPDATE glpi_states SET
    name = 'In production',
    completename = 'In production',
    level = 1,
    is_helpdesk_visible = 1
WHERE id = 200;
UPDATE glpi_states SET
    name = 'Maintenance',
    completename = 'Maintenance',
    level = 1,
    is_helpdesk_visible = 1
WHERE id = 201;
UPDATE glpi_states SET
    name = 'Spare',
    completename = 'Spare',
    level = 1,
    is_helpdesk_visible = 1
WHERE id = 202;
INSERT IGNORE INTO glpi_locations (id, name, completename, entities_id, locations_id, level) VALUES (200, 'Test Office', 'Test Office', @entity_id, 0, 1);
INSERT IGNORE INTO glpi_networkequipmentmodels (id, name) VALUES (200, 'Catalyst 2960');
INSERT IGNORE INTO glpi_networkequipmenttypes (id, name) VALUES (200, 'Switch');
INSERT IGNORE INTO glpi_rackmodels (id, name) VALUES (200, '42U Rack');
INSERT IGNORE INTO glpi_racktypes (id, name) VALUES (200, 'Server Rack');

-- Operating Systems
INSERT IGNORE INTO glpi_operatingsystems (id, name) VALUES 
(200, 'Windows 11'),
(201, 'Ubuntu Server'),
(202, 'Windows 10');

INSERT IGNORE INTO glpi_operatingsystemversions (id, name) VALUES
(200, '23H2'),
(201, '22.04 LTS'),
(202, '22H2');

INSERT IGNORE INTO glpi_operatingsystemarchitectures (id, name) VALUES
(200, 'x86_64');

-- Users
INSERT IGNORE INTO glpi_users (
    id, name, realname, firstname, phone, mobile, 
    entities_id, is_active, authtype
) VALUES
(200, 'jdoe', 'Doe', 'John', '+1-555-0101', '+1-555-0201', @entity_id, 1, 1),
(201, 'asmith', 'Smith', 'Alice', '+1-555-0102', '+1-555-0202', @entity_id, 1, 1),
(202, 'bwilliams', 'Williams', 'Bob', '+1-555-0103', '+1-555-0203', @entity_id, 1, 1),
(203, 'itadmin', 'Administrator', 'IT', '+1-555-0999', '+1-555-0899', @entity_id, 1, 1);

-- Update existing users if they already exist
UPDATE glpi_users SET name='jdoe', realname='Doe', firstname='John', phone='+1-555-0101', mobile='+1-555-0201' WHERE id=200;
UPDATE glpi_users SET name='asmith', realname='Smith', firstname='Alice', phone='+1-555-0102', mobile='+1-555-0202' WHERE id=201;
UPDATE glpi_users SET name='bwilliams', realname='Williams', firstname='Bob', phone='+1-555-0103', mobile='+1-555-0203' WHERE id=202;
UPDATE glpi_users SET name='itadmin', realname='Administrator', firstname='IT', phone='+1-555-0999', mobile='+1-555-0899' WHERE id=203;

-- User Titles
INSERT IGNORE INTO glpi_usertitles (id, name) VALUES
(200, 'Graphic Designer'),
(201, 'Finance Manager'),
(202, 'System Administrator');

-- Update user titles
UPDATE glpi_users SET usertitles_id = 200 WHERE id = 200;
UPDATE glpi_users SET usertitles_id = 201 WHERE id = 201;
UPDATE glpi_users SET usertitles_id = 202 WHERE id = 203;

-- User emails (used by {{asset_user_email}})
INSERT IGNORE INTO glpi_useremails (id, users_id, is_default, is_dynamic, email) VALUES
(200, 200, 1, 0, 'john.doe@example.test'),
(201, 201, 1, 0, 'alice.smith@example.test'),
(202, 202, 1, 0, 'bob.williams@example.test'),
(203, 203, 1, 0, 'it.admin@example.test');

-- Groups, domains and networks for richer report output
INSERT IGNORE INTO glpi_groups (
    id, entities_id, is_recursive, name, completename, level, groups_id, is_requester, is_watcher, is_assign, is_task, is_notify, is_itemgroup, is_usergroup, is_manager
) VALUES
(200, @entity_id, 0, 'Design Team', 'Design Team', 1, 0, 1, 1, 1, 1, 1, 1, 1, 0),
(201, @entity_id, 0, 'Finance Team', 'Finance Team', 1, 0, 1, 1, 1, 1, 1, 1, 1, 0),
(202, @entity_id, 0, 'Infrastructure Team', 'Infrastructure Team', 1, 0, 1, 1, 1, 1, 1, 1, 1, 1);

INSERT IGNORE INTO glpi_domains (
    id, name, entities_id, is_recursive, users_id, users_id_tech, is_active, is_deleted
) VALUES
(200, 'corp.example.test', @entity_id, 0, 203, 203, 1, 0),
(201, 'lab.example.test', @entity_id, 0, 203, 203, 1, 0);

INSERT IGNORE INTO glpi_networks (id, name, comment, date_creation, date_mod) VALUES
(200, 'Office LAN', 'Corporate office network', NOW(), NOW()),
(201, 'Datacenter VLAN', 'Server/datacenter network', NOW(), NOW());

-- Datacenter room for rack room placeholders
INSERT IGNORE INTO glpi_dcrooms (
    id, name, entities_id, is_recursive, locations_id, datacenters_id, is_deleted, date_creation, date_mod
) VALUES
(200, 'Datacenter Room A', @entity_id, 0, 200, 0, 0, NOW(), NOW());

-- Software inventory + versions + licenses for {{software*}} placeholders
INSERT IGNORE INTO glpi_softwares (
    id, entities_id, is_recursive, name, comment, locations_id, users_id_tech, users_id, softwarecategories_id, is_deleted, is_template, is_helpdesk_visible, is_valid, date_creation, date_mod
) VALUES
(200, @entity_id, 0, 'Microsoft Office', 'Office productivity suite', 200, 203, 203, 0, 0, 0, 1, 1, NOW(), NOW()),
(201, @entity_id, 0, 'Google Chrome', 'Web browser', 200, 203, 203, 0, 0, 0, 1, 1, NOW(), NOW()),
(202, @entity_id, 0, 'Docker Engine', 'Container runtime', 200, 203, 203, 0, 0, 0, 1, 1, NOW(), NOW());

INSERT IGNORE INTO glpi_softwareversions (
    id, entities_id, is_recursive, softwares_id, states_id, name, arch, operatingsystems_id, date_creation, date_mod
) VALUES
(200, @entity_id, 0, 200, 200, '2021 LTSC', 'x64', 200, NOW(), NOW()),
(201, @entity_id, 0, 201, 200, '126.0', 'x64', 200, NOW(), NOW()),
(202, @entity_id, 0, 202, 200, '26.1', 'x64', 201, NOW(), NOW());

INSERT IGNORE INTO glpi_softwarelicenses (
    id, softwares_id, softwarelicenses_id, entities_id, is_recursive, number, softwarelicensetypes_id, name, serial, softwareversions_id_buy, softwareversions_id_use, is_valid, is_deleted, locations_id, users_id_tech, users_id, is_helpdesk_visible, is_template, states_id, manufacturers_id, allow_overquota, date_creation, date_mod
) VALUES
(200, 200, 0, @entity_id, 0, 25, 0, 'Office Volume License', 'OFFICE-SN-200', 200, 200, 1, 0, 200, 203, 203, 1, 0, 200, 200, 0, NOW(), NOW()),
(201, 201, 0, @entity_id, 0, 100, 0, 'Chrome Enterprise License', 'CHROME-SN-201', 201, 201, 1, 0, 200, 203, 203, 1, 0, 200, 200, 0, NOW(), NOW()),
(202, 202, 0, @entity_id, 0, 20, 0, 'Docker Subscription', 'DOCKER-SN-202', 202, 202, 1, 0, 200, 203, 203, 1, 0, 200, 200, 0, NOW(), NOW());

-- Manufacturers for components
INSERT IGNORE INTO glpi_manufacturers (id, name) VALUES 
(201, 'Intel'),
(202, 'AMD'),
(203, 'Samsung'),
(204, 'Kingston'),
(205, 'NVIDIA'),
(206, 'Crucial'),
(207, 'Western Digital');

-- Device Processor Models
INSERT IGNORE INTO glpi_deviceprocessormodels (id, name) VALUES
(200, 'Core i7-11700'),
(201, 'Core i5-10400'),
(202, 'Xeon Silver 4214R');

-- Device Processors
INSERT IGNORE INTO glpi_deviceprocessors (
    id, designation, manufacturers_id, deviceprocessormodels_id, 
    frequency_default, nbcores_default, nbthreads_default, entities_id
) VALUES
(200, 'Intel Core i7-11700 @ 2.5GHz', 201, 200, 2500, 8, 16, @entity_id),
(201, 'Intel Core i5-10400 @ 2.9GHz', 201, 201, 2900, 6, 12, @entity_id),
(202, 'Intel Xeon Silver 4214R @ 2.4GHz', 201, 202, 2400, 12, 24, @entity_id);

-- Device Memory Types
INSERT IGNORE INTO glpi_devicememorytypes (id, name) VALUES
(200, 'DDR4');

-- Device Memory Models
INSERT IGNORE INTO glpi_devicememorymodels (id, name) VALUES
(200, 'DDR4-3200'),
(201, 'DDR4-2666');

-- Device Memories
INSERT IGNORE INTO glpi_devicememories (
    id, designation, manufacturers_id, devicememorymodels_id, 
    devicememorytypes_id, size_default, frequence, entities_id
) VALUES
(200, 'Samsung 16GB DDR4-3200', 203, 200, 200, 16384, '3200', @entity_id),
(201, 'Kingston 8GB DDR4-2666', 204, 201, 200, 8192, '2666', @entity_id),
(202, 'Samsung 32GB DDR4-3200', 203, 200, 200, 32768, '3200', @entity_id);

-- Device Hard Drive Types
INSERT IGNORE INTO glpi_deviceharddrivetypes (id, name) VALUES
(200, 'SSD'),
(201, 'HDD');

-- Device Hard Drive Models
INSERT IGNORE INTO glpi_deviceharddrivemodels (id, name) VALUES
(200, '970 EVO Plus'),
(201, 'Blue'),
(202, '860 EVO');

-- Device Hard Drives
INSERT IGNORE INTO glpi_deviceharddrives (
    id, designation, manufacturers_id, deviceharddrivemodels_id,
    deviceharddrivetypes_id, capacity_default, entities_id
) VALUES
(200, 'Samsung 970 EVO Plus 500GB NVMe', 203, 200, 200, 500, @entity_id),
(201, 'WD Blue 1TB HDD', 207, 201, 201, 1000, @entity_id),
(202, 'Samsung 860 EVO 2TB SSD', 203, 202, 200, 2000, @entity_id);

-- Device Graphics Card Models
INSERT IGNORE INTO glpi_devicegraphiccardmodels (id, name) VALUES
(200, 'GeForce GTX 1650'),
(201, 'Quadro P620');

-- Device Graphics Cards
INSERT IGNORE INTO glpi_devicegraphiccards (
    id, designation, manufacturers_id, devicegraphiccardmodels_id,
    memory_default, entities_id
) VALUES
(200, 'NVIDIA GeForce GTX 1650 4GB', 205, 200, 4096, @entity_id),
(201, 'NVIDIA Quadro P620 2GB', 205, 201, 2048, @entity_id);

-- Device Network Card Models
INSERT IGNORE INTO glpi_devicenetworkcardmodels (id, name) VALUES
(200, 'I219-LM'),
(201, 'I350-T4');

-- Device Network Cards
INSERT IGNORE INTO glpi_devicenetworkcards (
    id, designation, manufacturers_id, devicenetworkcardmodels_id,
    bandwidth, entities_id
) VALUES
(200, 'Intel I219-LM Gigabit Ethernet', 201, 200, '1000', @entity_id),
(201, 'Intel I350-T4 Quad Port Gigabit', 201, 201, '1000', @entity_id);

-- 3 Computers
INSERT IGNORE INTO glpi_computers (id, entities_id, name, serial, otherserial, uuid, contact, contact_num, comment, locations_id, networks_id, states_id, manufacturers_id, computermodels_id, computertypes_id, users_id, users_id_tech, date_creation, date_mod) VALUES
(200, @entity_id, 'WS-TEST-001', 'SN-WS-001', 'INV-001', 'uuid-200', 'John Doe', '+1-555-0101', 'Test Workstation', 200, 200, 200, 200, 200, 200, 200, 203, NOW(), NOW()),
(201, @entity_id, 'SRV-TEST-001', 'SN-SRV-001', 'INV-002', 'uuid-201', 'IT Ops', '+1-555-0999', 'Test Server', 200, 201, 201, 200, 200, 200, 0, 203, NOW(), NOW()),
(202, @entity_id, 'PC-TEST-001', 'SN-PC-001', 'INV-003', 'uuid-202', 'Alice Smith', '+1-555-0102', 'Test Desktop', 200, 200, 202, 200, 200, 200, 201, 203, NOW(), NOW());

-- Update computers with user assignments (in case they already exist)
UPDATE glpi_computers SET users_id = 200, users_id_tech = 203, contact = 'John Doe', contact_num = '+1-555-0101', networks_id = 200, states_id = 200 WHERE id = 200;
UPDATE glpi_computers SET users_id = 0, users_id_tech = 203, contact = 'IT Ops', contact_num = '+1-555-0999', networks_id = 201, states_id = 201 WHERE id = 201;
UPDATE glpi_computers SET users_id = 201, users_id_tech = 203, contact = 'Alice Smith', contact_num = '+1-555-0102', networks_id = 200, states_id = 202 WHERE id = 202;

-- Operating Systems for Computers
INSERT IGNORE INTO glpi_items_operatingsystems (
    items_id, itemtype, operatingsystems_id, operatingsystemversions_id, 
    operatingsystemarchitectures_id, entities_id, date_creation, date_mod
) VALUES
(200, 'Computer', 200, 200, 200, @entity_id, NOW(), NOW()),  -- WS-TEST-001: Windows 11 23H2 x64
(201, 'Computer', 201, 201, 200, @entity_id, NOW(), NOW()),  -- SRV-TEST-001: Ubuntu Server 22.04 x64
(202, 'Computer', 202, 202, 200, @entity_id, NOW(), NOW());  -- PC-TEST-001: Windows 10 22H2 x64

-- Components for WS-TEST-001 (Workstation with good specs)
INSERT IGNORE INTO glpi_items_deviceprocessors (
    items_id, itemtype, deviceprocessors_id, frequency, nbcores, nbthreads, entities_id
) VALUES
(200, 'Computer', 200, 2500, 8, 16, @entity_id);

INSERT IGNORE INTO glpi_items_devicememories (
    items_id, itemtype, devicememories_id, size, entities_id
) VALUES
(200, 'Computer', 200, 16384, @entity_id),  -- 16GB stick 1
(200, 'Computer', 200, 16384, @entity_id);  -- 16GB stick 2 (total: 32GB)

INSERT IGNORE INTO glpi_items_deviceharddrives (
    items_id, itemtype, deviceharddrives_id, capacity, entities_id
) VALUES
(200, 'Computer', 200, 500, @entity_id);  -- 500GB NVMe SSD

INSERT IGNORE INTO glpi_items_devicenetworkcards (
    items_id, itemtype, devicenetworkcards_id, entities_id
) VALUES
(200, 'Computer', 200, @entity_id);  -- Gigabit Ethernet

INSERT IGNORE INTO glpi_items_devicegraphiccards (
    items_id, itemtype, devicegraphiccards_id, memory, entities_id
) VALUES
(200, 'Computer', 200, 4096, @entity_id);  -- GTX 1650 4GB

-- Components for SRV-TEST-001 (Server with high-end specs)
INSERT IGNORE INTO glpi_items_deviceprocessors (
    items_id, itemtype, deviceprocessors_id, frequency, nbcores, nbthreads, entities_id
) VALUES
(201, 'Computer', 202, 2400, 12, 24, @entity_id);

INSERT IGNORE INTO glpi_items_devicememories (
    items_id, itemtype, devicememories_id, size, entities_id
) VALUES
(201, 'Computer', 202, 32768, @entity_id),  -- 32GB stick 1
(201, 'Computer', 202, 32768, @entity_id),  -- 32GB stick 2
(201, 'Computer', 202, 32768, @entity_id),  -- 32GB stick 3
(201, 'Computer', 202, 32768, @entity_id);  -- 32GB stick 4 (total: 128GB)

INSERT IGNORE INTO glpi_items_deviceharddrives (
    items_id, itemtype, deviceharddrives_id, capacity, entities_id
) VALUES
(201, 'Computer', 202, 2000, @entity_id),  -- 2TB SSD 1
(201, 'Computer', 202, 2000, @entity_id);  -- 2TB SSD 2 (total: 4TB)

INSERT IGNORE INTO glpi_items_devicenetworkcards (
    items_id, itemtype, devicenetworkcards_id, entities_id
) VALUES
(201, 'Computer', 201, @entity_id);  -- Quad Port Gigabit

-- Components for PC-TEST-001 (Standard desktop)
INSERT IGNORE INTO glpi_items_deviceprocessors (
    items_id, itemtype, deviceprocessors_id, frequency, nbcores, nbthreads, entities_id
) VALUES
(202, 'Computer', 201, 2900, 6, 12, @entity_id);

INSERT IGNORE INTO glpi_items_devicememories (
    items_id, itemtype, devicememories_id, size, entities_id
) VALUES
(202, 'Computer', 201, 8192, @entity_id),  -- 8GB stick 1
(202, 'Computer', 201, 8192, @entity_id);  -- 8GB stick 2 (total: 16GB)

INSERT IGNORE INTO glpi_items_deviceharddrives (
    items_id, itemtype, deviceharddrives_id, capacity, entities_id
) VALUES
(202, 'Computer', 201, 1000, @entity_id);  -- 1TB HDD

INSERT IGNORE INTO glpi_items_devicenetworkcards (
    items_id, itemtype, devicenetworkcards_id, entities_id
) VALUES
(202, 'Computer', 200, @entity_id);  -- Gigabit Ethernet

INSERT IGNORE INTO glpi_items_devicegraphiccards (
    items_id, itemtype, devicegraphiccards_id, memory, entities_id
) VALUES
(202, 'Computer', 201, 2048, @entity_id);  -- Quadro P620 2GB

-- Network ports for computers
INSERT IGNORE INTO glpi_networkports (id, items_id, itemtype, entities_id, name, mac, instantiation_type, logical_number) VALUES
(200, 200, 'Computer', @entity_id, 'eth0', '00:aa:00:00:02:00', 'NetworkPortEthernet', 1),
(201, 201, 'Computer', @entity_id, 'eth0', '00:aa:00:00:02:01', 'NetworkPortEthernet', 1),
(202, 202, 'Computer', @entity_id, 'eth0', '00:aa:00:00:02:02', 'NetworkPortEthernet', 1);

INSERT IGNORE INTO glpi_networkportethernets (id, networkports_id, speed) VALUES
(200, 200, 1000),
(201, 201, 1000),
(202, 202, 1000);

INSERT IGNORE INTO glpi_networknames (id, entities_id, items_id, itemtype, name) VALUES
(200, @entity_id, 200, 'NetworkPort', 'WS-TEST-001-eth0'),
(201, @entity_id, 201, 'NetworkPort', 'SRV-TEST-001-eth0'),
(202, @entity_id, 202, 'NetworkPort', 'PC-TEST-001-eth0');

INSERT IGNORE INTO glpi_ipaddresses (id, entities_id, items_id, itemtype, name, version) VALUES
(200, @entity_id, 200, 'NetworkName', '192.168.100.10', 4),
(201, @entity_id, 201, 'NetworkName', '192.168.100.11', 4),
(202, @entity_id, 202, 'NetworkName', '192.168.100.12', 4);

-- 3 Network Equipment
INSERT IGNORE INTO glpi_networkequipments (id, entities_id, name, serial, otherserial, uuid, contact, contact_num, comment, locations_id, states_id, manufacturers_id, networkequipmentmodels_id, networkequipmenttypes_id, users_id, users_id_tech, date_creation, date_mod) VALUES
(200, @entity_id, 'SW-TEST-001', 'SN-SW-001', 'NET-001', 'uuid-sw-200', 'Network Team', '+1-555-0100', 'Test Switch', 200, 200, 200, 200, 200, 202, 203, NOW(), NOW()),
(201, @entity_id, 'RTR-TEST-001', 'SN-RTR-001', 'NET-002', 'uuid-rtr-201', 'Network Team', '+1-555-0100', 'Test Router', 200, 200, 200, 200, 200, 0, 203, NOW(), NOW()),
(202, @entity_id, 'SW-TEST-002', 'SN-SW-002', 'NET-003', 'uuid-sw-202', 'Network Team', '+1-555-0100', 'Test Access Switch', 200, 200, 200, 200, 200, 202, 203, NOW(), NOW());

-- Update network equipment user assignments (for re-running seed file)
UPDATE glpi_networkequipments SET users_id = 202, users_id_tech = 203 WHERE id = 200;
UPDATE glpi_networkequipments SET users_id = 0, users_id_tech = 203 WHERE id = 201;
UPDATE glpi_networkequipments SET users_id = 202, users_id_tech = 203 WHERE id = 202;

-- Network ports for network equipment  
INSERT IGNORE INTO glpi_networkports (id, items_id, itemtype, entities_id, name, mac, instantiation_type, logical_number) VALUES
(210, 200, 'NetworkEquipment', @entity_id, 'Gi1/0/1', '00:bb:00:00:02:10', 'NetworkPortEthernet', 1),
(220, 201, 'NetworkEquipment', @entity_id, 'Gi0/0/0', '00:bb:00:00:02:20', 'NetworkPortEthernet', 1),
(230, 202, 'NetworkEquipment', @entity_id, 'Gi1/0/1', '00:bb:00:00:02:30', 'NetworkPortEthernet', 1);

INSERT IGNORE INTO glpi_networkportethernets (id, networkports_id, speed) VALUES
(210, 210, 1000),
(220, 220, 1000),
(230, 230, 1000);

INSERT IGNORE INTO glpi_networknames (id, entities_id, items_id, itemtype, name) VALUES
(210, @entity_id, 210, 'NetworkPort', 'SW-TEST-001-mgmt'),
(220, @entity_id, 220, 'NetworkPort', 'RTR-TEST-001-mgmt'),
(230, @entity_id, 230, 'NetworkPort', 'SW-TEST-002-mgmt');

INSERT IGNORE INTO glpi_ipaddresses (id, entities_id, items_id, itemtype, name, version) VALUES
(210, @entity_id, 210, 'NetworkName', '192.168.100.1', 4),
(220, @entity_id, 220, 'NetworkName', '192.168.100.254', 4),
(230, @entity_id, 230, 'NetworkName', '192.168.100.2', 4);

-- 2 Racks
INSERT IGNORE INTO glpi_racks (id, entities_id, name, serial, otherserial, comment, locations_id, states_id, manufacturers_id, rackmodels_id, racktypes_id, number_units, dcrooms_id, position, bgcolor, max_power, mesured_power, max_weight, users_id, users_id_tech, date_creation, date_mod) VALUES
(200, @entity_id, 'RACK-TEST-001', 'RACK-SN-001', 'RACK-001', 'Test Server Rack', 200, 200, 200, 200, 200, 42, 200, 'A-01', '#1F75FE', 12000, 3500, 1200, 0, 203, NOW(), NOW()),
(201, @entity_id, 'RACK-TEST-002', 'RACK-SN-002', 'RACK-002', 'Test Network Rack', 200, 200, 200, 200, 200, 42, 200, 'A-02', '#28A745', 8000, 1800, 900, 0, 203, NOW(), NOW());

-- Update rack user assignments (for re-running seed file)
UPDATE glpi_racks SET users_id = 0, users_id_tech = 203, dcrooms_id = 200, position = 'A-01', bgcolor = '#1F75FE', max_power = 12000, mesured_power = 3500, max_weight = 1200 WHERE id = 200;
UPDATE glpi_racks SET users_id = 0, users_id_tech = 203, dcrooms_id = 200, position = 'A-02', bgcolor = '#28A745', max_power = 8000, mesured_power = 1800, max_weight = 900 WHERE id = 201;
-- Keep rack colors always valid for dcroom_racks.html.twig (Html::getInvertedColor requires HEX).
UPDATE glpi_racks
SET bgcolor = '#6C757D'
WHERE dcrooms_id > 0
  AND (
      bgcolor IS NULL
      OR bgcolor = ''
      OR bgcolor NOT REGEXP '^#[0-9A-Fa-f]{6}$'
  );

-- Group assignments (for {{asset_group}})
INSERT IGNORE INTO glpi_groups_items (id, groups_id, itemtype, items_id, type) VALUES
(200, 200, 'Computer', 200, 1),
(201, 202, 'Computer', 201, 1),
(202, 201, 'Computer', 202, 1),
(203, 202, 'NetworkEquipment', 200, 1),
(204, 202, 'NetworkEquipment', 201, 1),
(205, 202, 'NetworkEquipment', 202, 1),
(206, 202, 'Rack', 200, 1),
(207, 202, 'Rack', 201, 1);

-- Domain assignments (for {{asset_domain}})
INSERT IGNORE INTO glpi_domains_items (id, domains_id, items_id, itemtype, domainrelations_id, is_dynamic, is_deleted) VALUES
(200, 200, 200, 'Computer', 0, 0, 0),
(201, 200, 202, 'Computer', 0, 0, 0),
(202, 201, 201, 'Computer', 0, 0, 0),
(203, 200, 200, 'NetworkEquipment', 0, 0, 0),
(204, 201, 201, 'NetworkEquipment', 0, 0, 0),
(205, 200, 202, 'NetworkEquipment', 0, 0, 0),
(206, 201, 200, 'Rack', 0, 0, 0),
(207, 200, 201, 'Rack', 0, 0, 0);

-- Software/version links (for {{software}})
INSERT IGNORE INTO glpi_items_softwareversions (id, items_id, itemtype, softwareversions_id, is_deleted_item, is_template_item, entities_id, is_deleted, is_dynamic, date_install) VALUES
(200, 200, 'Computer', 200, 0, 0, @entity_id, 0, 0, CURDATE()),
(201, 200, 'Computer', 201, 0, 0, @entity_id, 0, 0, CURDATE()),
(202, 201, 'Computer', 202, 0, 0, @entity_id, 0, 0, CURDATE()),
(203, 202, 'Computer', 201, 0, 0, @entity_id, 0, 0, CURDATE());

-- Software license links (for {{software_serial}})
INSERT IGNORE INTO glpi_items_softwarelicenses (id, items_id, itemtype, softwarelicenses_id, is_deleted, is_dynamic) VALUES
(200, 200, 'Computer', 200, 0, 0),
(201, 200, 'Computer', 201, 0, 0),
(202, 201, 'Computer', 202, 0, 0),
(203, 202, 'Computer', 201, 0, 0);

-- Items in racks
INSERT IGNORE INTO glpi_items_racks (itemtype, items_id, racks_id, position, orientation) VALUES
('Computer', 201, 200, 10, 1),
('NetworkEquipment', 200, 201, 5, 1);
