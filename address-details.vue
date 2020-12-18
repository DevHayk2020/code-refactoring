<template>
    <section class="sidebar address-details">

        <address-details-usa v-if="user.default_country === 'usa-private'"></address-details-usa>

        <div v-if="user.default_country !== 'usa-private'" class="address-details-container slider-container" :class="{expanded: isExpanded}">

            <div class="slided-box">

                <div v-if="sideComponentToDisplay == 'all-employee'">
                    <all-employee-list
                        :addressId="addressId"
                        :isActive="isExpanded && sideComponentToDisplay == 'all-employee'"
                        :employeeList="addressData.people"
                        :address="addressData"
                        :customerTypes="filterObject.customer_types"
                        @closeSlidedBox="onCloseSlidedBox"
                    ></all-employee-list>
                </div>

                <div v-if="sideComponentToDisplay == 'lab-chain-details'">
                    <lab-chain-details
                        :isActive="isExpanded && sideComponentToDisplay == 'lab-chain-details'"
                        :addressId="addressId"
                        :addressData="addressData"
                        @closeSlidedBox="onCloseSlidedBox"
                    ></lab-chain-details>
                </div>

                <div v-if="sideComponentToDisplay == 'all-products'">
                    <all-products-list
                            :addressId="addressId"
                            :purchaseId="174"
                            :addressData="addressData"
                            :isActive="isExpanded && sideComponentToDisplay == 'all-products'"
                            @closeSlidedBox="isExpanded = false"
                    ></all-products-list>
                </div>

                <div v-if="sideComponentToDisplay == 'address-values-details-sidebar-slider'">
                    <address-values-details-sidebar-slider
                            :addressId="addressId"
                            :isActive="isExpanded && sideComponentToDisplay == 'address-values-details-sidebar-slider'"
                            :address="addressData"
                            @closeSlidedBox="onCloseSlidedBox"
                    ></address-values-details-sidebar-slider>
                </div>

            </div>

            <div class="address-details-fixed-height">

                <a href="javascript:void(0)" @click="returnToPreviousDashboard()" title="Back" class="link-back arrow-left">
                    <i class="fa fa-angle-left"></i>
                </a>

                <div class="address-overview">

                    <div class="row">

                        <div class="col-sm-7" style="padding-right: 0;">
                            <div v-if=" ! isEditing">
                                <h2>
                                    <span style="vertical-align: middle">{{ addressData.name }}</span>

                                    <a href="javascript:void(0)" @click="toggleEditing" :class="{'active': isEditing}">
                                        <i class="fa fa-pencil"></i>
                                    </a>

                                    <a href="javascript:void(0)" title="Show on Map" @click="showOnMap()"><i class="fa fa-map-marker"></i></a>
                                </h2>

                                <div style="clear: both"></div>

                                <p class="lab-chain" v-if="APP_SCOPE === 'labscape'">
                                    <span class="lab-chain-text">{{WORDS['cluster'][APP_SCOPE]}}:</span>
                                    <span class="current-chain-name">
                                        {{addressData.cluster ? addressData.cluster.name : 'No chain'}}
                                    </span>
                                </p>

                                <p class="lab-chain" v-if="APP_SCOPE === 'wealthscape'"></p>

                                <ul class="tag-list" v-if="addressData.tags.length">
                                    <li v-for="tag in addressData.tags" :key="tag.id">
                                        <a href="#" @click.prevent>
                                            {{ tag.name }}
                                        </a>
                                    </li>
                                </ul>
                                <span v-else>No tags</span>

                                <p class="address-line">
                                    {{ addressData.address }}
                                </p>

                                <p class="link-and-phone">
                                    <a :href="addressData.url.indexOf('http') !== -1 ? addressData.url : 'http://'+addressData.url" target="_blank" v-if="addressData.url">
                                        {{addressData.url.replace('https://', '').replace('http://', '') }}
                                    </a>
                                    <span class="pone-number">{{ addressData.phone }}</span>
                                </p>
                            </div>

                            <div v-else>
                                <div @click="toggleEditingInput('name')">
                                    <div class="name-block can-edit">
                                        <div-editable
                                                @updateEdit="updateAddress"
                                                :content.sync="addressData.name"
                                                :placeholder="'Name'"
                                        ></div-editable>
                                    </div>
                                </div>

                                <div style="clear: both"></div>

                                <p class="lab-chain" v-if="APP_SCOPE === 'labscape'">
                                    <span class="lab-chain-text">Health System:</span>
                                    <span class="current-chain-name">
                                        {{addressData.cluster ? addressData.cluster.name : 'No chain'}}
                                    </span>
                                    <a href="#" @click.prevent="toggleChain" class="add-to-chain-link">Set</a>

                                    <br />

                                    <autocompleteSelect
                                            v-if="chainSelect"
                                            :type="'clusters'"
                                            :selected="addressData.cluster"
                                            :close="closeChain"
                                            :choose="addChain"
                                            :createNewChain="createNewChain"
                                    />
                                </p>

                                <p class="lab-chain" v-if="APP_SCOPE === 'wealthscape'"></p>

                                <ul v-if="editingInput !== 'tags' && addressData.tags.length" class="tag-list tags-edit">
                                    <li v-for="tag in addressData.tags" :key="tag.id">
                                        <a href="#" @click.prevent>
                                            {{ tag.name }}
                                            <button class="delete-tag" @click="removeSelectedTag(tag.name)">
                                                <span aria-hidden="true">&times;</span>
                                            </button>
                                        </a>
                                    </li>
                                    <li>
                                        <a href="#" @click.prevent="toggleEditingInput('tags')" class="add-tag">
                                            Add Tag
                                        </a>
                                    </li>
                                </ul>

                                <v-select
                                        v-show="editingInput === 'tags'"
                                        v-model="addressData.tags"
                                        :options="allTags"
                                        :label="'name'"
                                        :class="'tags-select'"
                                        multiple
                                        taggable
                                        push-tags
                                        :placeholder="'Select tags'"
                                />

                                <p class="address-line can-edit" @click="toggleEditingInput('address')">
                                    <div-editable
                                            @updateEdit="updateAddress"
                                            :content.sync="addressData.address"
                                            :placeholder="'Address'"
                                    ></div-editable>
                                </p>

                                <p class="address-line can-edit" @click="toggleEditingInput('url')">
                                    <div-editable
                                            @updateEdit="updateAddress"
                                            :content.sync="addressData.url"
                                            :placeholder="'URL'"
                                    ></div-editable>
                                </p>

                                <p class="address-line can-edit" @click="toggleEditingInput('phone')">
                                    <div-editable
                                            @updateEdit="updateAddress"
                                            :content.sync="addressData.phone"
                                            :placeholder="'Phone Number'"
                                    ></div-editable>
                                </p>

                                <div class="confirm-edit-block">
                                    <button
                                            type="button"
                                            @click="toggleEditing"
                                            class="btn cancel-address-btn"
                                    >
                                        Cancel Editing
                                    </button>
                                    <button
                                            type="submit"
                                            v-if=" ! saveBtnDisabled && madeChanges"
                                            @click.prevent="updateAddress"
                                            class="btn save-address-btn"
                                    >
                                        Suggest Edits
                                    </button>
                                    <button
                                            type="button"
                                            v-if="saveBtnDisabled || ! madeChanges"
                                            disabled
                                            class="btn save-address-btn-disabled"
                                    >
                                        Suggest Edits
                                    </button>

                                </div>
                            </div>
                        </div>

                        <div class="col-sm-5 col-xs-12">
                            <div v-if="!addressData.is_in_ignore_list" class="pull-right right-side-block">
                                <customer-status-select
                                        v-if="APP_SCOPE === 'labscape'"
                                        :options="customerStatusList"
                                        :selected="addressData.customer_status"
                                        :addressId="addressId"
                                        :cssClass="'address-select'"
                                        @customerStatusUpdated="updateCustomerStatus"
                                ></customer-status-select>

                                <wealthscape-customer-status
                                        v-if="APP_SCOPE === 'wealthscape' && addressData.customer_status !== null"
                                        :customerStatus="+addressData.customer_status"
                                        :type="'address'"
                                        :entityId="addressData.id"
                                        @customerStatusUpdated="updateCustomerStatus"
                                ></wealthscape-customer-status>

                                <add-to-favorite
                                        :isFavorite="addressData.is_favorite"
                                        :entityId="+addressId"
                                        :entityName="'ADDRESS'"
                                        @favoriteChanged="handleFavoriteChanged"
                                ></add-to-favorite>

                                <assign-to v-if="APP_SCOPE === 'wealthscape'"
                                           :entity="addressData"
                                           :entityType="'address'"
                                           @assignToUpdated="loadAddressDetails()"
                                ></assign-to>

                                <div class="add-to-ignore-box" v-if="APP_SCOPE === 'wealthscape'">
                                    <a @click="showDislikeConfirmation()"><i class="fa fa-thumbs-o-down"></i> Add to ignore...</a>
                                </div>
                            </div>

                            <div v-if="addressData.is_in_ignore_list" class="pull-right right-side-block">
                                <a @click="removeAddressFromIgnore()"><i class="fa fa-arrow-up"></i> Remove from ignore</a>
                            </div>
                        </div>

                    </div>
                </div>

                <scalar-values-table
                        v-if="COUNTRY_FEATURES['scalar-values-for-addresses']"
                        @showSlidedBox="showSlidedBox('address-values-details-sidebar-slider')"
                ></scalar-values-table>

                <div class="staff-overview address-box">
                    <div class="header">
                        <h3>Current and past people
                            <a href="#"
                               v-if="COUNTRY_FEATURES['editable-side-employee-list']"
                               @click.prevent
                               class="without-handler"
                            >
                                <i class="fa fa-pencil"></i>
                            </a>
                        </h3>
                        <!--<a href="javascript:void(0)"
                            @click="showContactsChain(addressData)" 
                            class="view-contacts-chain"
                           v-if="COUNTRY_FEATURES['view-address-relationship-graph']"
                        >
                            View Relationship Graph
                        </a>-->

                        <a :href="'/'+$route.params['country']+'/address-relationship-graph/'+addressId"
                           @click.prevent="navigateToRelationshipGraphPage()"
                           class="view-contacts-chain"
                           :class="{'disabled': !addressData.has_relationships_for_graph}"
                           v-if="COUNTRY_FEATURES['view-address-relationship-graph']"
                        >
                            View Relationship Graph
                        </a>
                    </div>

                    <div class="row chart-container">

                        <div class="col-md-4"
                             v-if="APP_SCOPE !== 'wealthscape' && COUNTRY_FEATURES['graph-employee-by-role']"
                             @click="$eventGlobal.$emit('staffOverviewModalShown', 'staff-by-speciality')"
                             style="cursor: pointer"
                        >

                            <staff-by-speciality-graph
                                    :params="{legend:{position: 'none'}}"
                            ></staff-by-speciality-graph>
                        </div>

                        <div class="col-md-8"
                             v-if="APP_SCOPE === 'labscape' && COUNTRY_FEATURES['graph-employee-co-auth-citations-publications']"
                             @click="$eventGlobal.$emit('staffOverviewModalShown', 'metrics')"
                             style="cursor: pointer"
                        >
                            <relations-graph></relations-graph>
                        </div>

                        <div class="col-md-8"
                             v-if="false && COUNTRY_FEATURES['chart-interesting-signatories']"
                             style="cursor: pointer"
                             @click="$eventGlobal.$emit('staffOverviewModalShown', 'metrics')"
                        >
                            <chart-interesting-signatories></chart-interesting-signatories>
                        </div>
                    </div>

                    <p v-if="!addressData.people.length" class="empty-data-p">There are no employees yet.</p>

                    <ul class="staff-list">
                        <li v-if="i < 3 && addressData.people.length" v-for="(person, i) in addressData.people" :key="person.id">
                            <div class="image">
                                <a href="javascript:void(0)"
                                   @click="showEmployeeDetailsModal(person.id, addressData.id, addressData)"
                                >
                                    <span class="person-initials">{{ getPersonInitials(person.name) }}</span>
                                    <img :src="'/images/mask-'+i+'.png'" alt="">
                                </a>
                            </div>
                            <div class="personal-info">
                                <p class="name">
                                    <a href="javascript:void(0)"
                                        @click="showEmployeeDetailsModal(person.id, addressData.id, addressData)"
                                    >
                                        {{ person.name }}

                                        <span class="oval"
                                              v-if="APP_SCOPE === 'wealthscape'"
                                              :style="'background-color: ' + customerStatusBackgroundColor(person.customer_status)"
                                        ></span>
                                    </a>
                                </p>
                                <p class="occupation">{{ person.role }}</p>
                                <p class="occupation">{{ person.description }}</p>
                            </div>
                            <div class="personal-relationship-number-box" title="Number of relationships">
                                <img v-if="person.people_in_relations_number > 0" src="/images/employees-relationship-number-icon.png" alt="">
                                <img v-if="person.people_in_relations_number == 0" style="opacity: 0.7" src="/images/employees-relationship-number-icon-black-white.png" alt="">
                                <span>{{person.people_in_relations_number}}</span>
                            </div>
                        </li>
                    </ul>

                    <div style="clear: both"></div>

                    <a href="javascript:void(0)"
                       v-if="addressData.people && addressData.people.length > 3"
                       @click.prevent="showSlidedBox('all-employee')"
                       class="address-box-show-more-link show-all-employees-link"
                    >
                        Show all affiliated people
                    </a>
                </div>

                <div class="used-products-overview address-box" v-if="APP_SCOPE !== 'wealthscape'">
                    <div class="header">
                        <h3>
                            Used Products 
                            <a href="javascript:void(0)"
                               v-if="COUNTRY_FEATURES['editable-side-product-list']"
                               @click.prevent="toggleProducts">
                                <i class="fa fa-pencil"></i>
                            </a>
                        </h3>
                    </div>

                    <div v-if="addressId && COUNTRY_FEATURES['side-product-list-with-chart']">
                        <address-products-overview
                                :addressId="addressId">
                        </address-products-overview>
                    </div>

                    <div v-if="COUNTRY_FEATURES['editable-side-product-list']">

                        <multiple-autocomplete-select
                                v-if="isProductsEditing"
                                :selectedOptions="addressData.products"
                                :type="'products'"
                                :close="closeProducts"
                                :update="updateProducts"
                                :addNewProduct="addNewProduct"
                        />

                        <p v-if=" ! addressData.products.length" class="empty-data-p">There are no used products</p>

                        <ul class="products-list" v-if="addressData.products.length && (( ! showAllProducts && i < 3) || showAllProducts)">
                            <li v-for="(product, i) in addressData.products" v-bind:key="i">
                                <img
                                        v-if="product.image"
                                        class="image"
                                        :src="product.image"
                                        alt=""
                                        :title="productName(product.company, product.name)"
                                >
                                <img
                                        v-else
                                        class="image"
                                        :src="'/images/mask-'+i+'.png'"
                                        alt=""
                                        :title="productName(product.company, product.name)"
                                >
                                <span class="product-description">
                                    {{ product.name ? product.company + ': ' + product.name : product.company }}
                                    <br v-if="product.pivot && product.pivot.updated_at">
                                    {{product.pivot && product.pivot.updated_at ? 'Updated at: ' + (product.pivot.updated_at.split(' '))[0] : '' }}
                                </span>
                            </li>
                        </ul>

                        <a
                                href="#"
                                @click.prevent="toggleShowAllProducts"
                                v-if="addressData.products.length > 3"
                                class="show-all-products-link"
                        >
                            {{ showHideProducts }}
                        </a>

                        <ul class="used-products-list" v-if="addressData.products.length"> <!--TODO: remove "... && false" when start to work on address products feature-->
                        <div v-if="! showAllProducts">
                            <li v-for="product in addressData.products.slice(3)"
                                :title="productName(product.company, product.name)"
                                :key="product.id"
                            >
                                <img class="image" :src="product.image" v-if="product.image">
                                <div class="image" v-else></div>
                                <span class="prod-name" style="z-index: 100">
                                    {{ productName(product.company, product.name) }}
                                </span>
                            </li>
                        </div>
                        <div v-if="showAllProducts">
                            <li
                                v-for="product in addressData.products"
                                :title="productName(product.company, product.name)"
                                :key="product.id"
                            >
                                <img class="image" :src="product.image" v-if="product.image">
                                <div class="image" v-else></div>
                            </li>
                        </div>

                            <li v-if="addressData.products.length > 3">
                                <a href="#" @click.prevent="toggleShowAllProducts" class="show-all-link prod-name">
                                    {{ showHideProducts }}
                                </a>
                            </li>
                        </ul>

                    </div>
                    
                </div>

                <div class="lab-chain-members-overview address-box" v-if="APP_SCOPE !== 'wealthscape' && addressData.cluster">
                    <div class="header">
                        <h3>Health System Members
                            <small :title="'Addresses in chain: ' + addressData.cluster.addresses_count">
                                ({{addressData.cluster.addresses_count}})
                            </small>
                        </h3>
                    </div>

                    <p v-if="addressData.cluster.addresses.length === 1" 
                        class="empty-data-p">
                        Current address is the only member in this chain
                    </p>

                    <ul class="lab-chain-member-list">
                        <li
                            v-for="(c, i) in addressData.cluster.addresses.filter(item => item.id != addressData.id).slice(3)"
                            :key="c.id"
                        >
                            <h4><router-link :to="'/address-details/'+c.id">{{c.name}}</router-link></h4>
                            <p>{{c.address}}</p>
                        </li>
                    </ul>

                    <a href="javascript:void(0)" @click="showSlidedBox('lab-chain-details')" v-if="addressData.cluster.addresses.length > 1" class="address-box-show-more-link">Health System Details</a>
                </div>

                <quick-feed-box v-if="IS_FEEDS_ENABLED" ref="quickFeedBoxRef"></quick-feed-box>

            </div>

        </div>
    </section>
</template>

<script>

    import http from '../../mixins/http';
    import addressHelpers from '../../mixins/address-helpers';
    import employeeModal from '../../mixins/show-employee-details-modal';
    import getPersonInitials from '../../mixins/get-person-initials';
    import autocompleteSelect from '../../partial-view-components/autocomplete-select';
    import multipleAutocompleteSelect from '../../partial-view-components/multiple-autocomplete-select';
    import mapHoveringNotified from '../../mixins/notify-map-that-hovering-over-list-item';
    import helpers from '../../mixins/helpers';
    import filtersMixin from '../../mixins/filters-mixin';
    import relationsGraph from '../../partial-view-components/address-details-usa/relations-graph';
    import staffBySpecialityGraph from '../../partial-view-components/staff-by-speciality-graph';
    import scalarValuesTable from '../../partial-view-components/scalar-values-table/scalar-values-table'

    export default {

        name: 'address-details',

        mixins: [http, employeeModal, getPersonInitials, mapHoveringNotified, helpers, addressHelpers, filtersMixin ],
        components: {
            autocompleteSelect,
            multipleAutocompleteSelect,
            relationsGraph,
            staffBySpecialityGraph,
            scalarValuesTable,
        },
        data: function () {
            return {
                addressId: null,
                addressData: {
                    tags: [],
                    url: '',
                    cluster: {
                        addresses: []
                    },
                    people: [],
                    products: [],
                    is_favorite: false,
                    customer_status: null
                },
                customerStatusList: [],
                chainList: [],
                isExpanded: false,
                sideComponentToDisplay: '',
                isFirstLoad: true,
                chainSelect: false,
                isEditing: false,
                editingInput: null,
                saveBtnDisabled: false,
                madeChanges: false,
                old: {
                    name: '',
                    address: '',
                    url: '',
                    phone: '',
                    tags: []
                },
                allTags: [],

                isProductsEditing: false,
                showAllProducts: false,
                user: JSON.parse(localStorage.getItem('logged-user'))
            }
        },

        watch:{
            $route: function(to, from){

                if(to.params['id'] === from.params['id']) {
                    return;
                }

                this.setAddressMouseLeaveListener();

                this.addressId = this.$route.params['id'];
                this.loadAddressDetails()
                    .then(()=>{
                        this.setAddressMouseOverListener([this.addressData]);
                    });

                let hashToPerson = (to.hash.split('&'))[0];
                let hashFromPerson = (from.hash.split('&'))[0];

                if( ! this.isFirstLoad && hashToPerson !== hashFromPerson) {
                    this.showModalIfPersonHashDetected(this.addressId, this.addressData);
                }
            },
            isEditing: function () {
                if ( ! this.isEditing) {
                    this.editingInput = null;
                }
            },
            "addressData.name": function () {
                this.checkIfInputsEmpty();
                if (this.isEditing) {
                    this.checkIfChangesMade();
                }
            },
            "addressData.address": function () {
                this.checkIfInputsEmpty();
                if (this.isEditing) {
                    this.checkIfChangesMade();
                }
            },
            "addressData.url": function () {
                this.checkIfInputsEmpty();
                if (this.isEditing) {
                    this.checkIfChangesMade();
                }
            },
            "addressData.phone": function () {
                this.checkIfInputsEmpty();
                if (this.isEditing) {
                    this.checkIfChangesMade();
                }
            },
            "addressData.tags": function () {
                this.checkIfInputsEmpty();
                if (this.isEditing) {
                    this.checkIfChangesMade();
                }
            }
        },

        methods: {
            checkIfInputsEmpty: function () {
                this.saveBtnDisabled = this.addressData.name === '' ||
                    this.addressData.address === ''
                    ? true : false;
            },
            checkIfChangesMade: function () {
                this.madeChanges = this.addressData.name !== this.old.name ||
                    this.addressData.phone !== this.old.phone ||
                    this.addressData.address !== this.old.address ||
                    this.addressData.url !== this.old.url ||
                    this.compareTags() 
                    ? true : false;
            },
            compareTags: function() {
                let _this = this,
                    sortedTags = this.addressData.tags.slice(),
                    sortedOldTags = this.old.tags.slice();

                if (this.addressData.tags) {

                    sortedTags.sort((tagA, tagB) => {
                        return tagA.name > tagB.name;
                    });

                    sortedOldTags.sort((tagA, tagB) => {
                        return tagA.name > tagB.name;
                    });

                    if (sortedTags.length === sortedOldTags.length) {
                        for (let i = 0; i < sortedTags.length; i++) {
                            if (sortedTags[i].name !== sortedOldTags[i].name) {
                                _this.madeChanges = true;
                                break;
                            } else {
                                _this.madeChanges = false;
                            }
                        }
                    } else {
                        _this.madeChanges = true;
                    }
                }

                return _this.madeChanges ? true : false ;
            },
            loadAddressDetails: function () {

                this.addressData.customer_status = null;

                return this.httpGet('/api/address-details/' + this.addressId)
                    .then(data => {
                        this.addressData = data;

                        if(!this.addressData.cluster){
                            this.addressData.cluster = {addresses: [], name: ''};
                        }

                        document.title = this.addressData.name;

                        this.$eventGlobal.$emit('addressDetailsLoaded', true);
                    })
            },
            loadCustomerStatusList: function () {
                this.httpGet('/api/customer-statuses')
                    .then(data => {
                        this.customerStatusList = data;
                    })
            },
            loadChainList: function () {
                this.httpGet('/api/clusters')
                    .then(data => {
                        this.chainList = data;
                    })
            },
            addChain: function (id) {
                this.$root.logData('detail', 'add chain', JSON.stringify({
                        cluster_id: id
                    }));
                this.httpPut('/api/clusters/' + this.addressId, {
                        cluster_id: id
                    })
                    .then(data => {
                        this.addressData.cluster.id = data.cluster.id
                        this.addressData.cluster.name = data.cluster.name
                        this.addressData.cluster.addresses = data.cluster.addresses
                        alertify.notify('Chain has been updated.', 'success', 3);
                        this.chainSelect = false;
                        this.$eventGlobal.$emit('addressClusterUpdated');
                    })
                    .catch(err => {
                        alertify.notify('Error occurred', 'error', 3);
                    })
            },
            updateCustomerStatus: function (status) {
                this.$root.logData('detail', 'update customer status', JSON.stringify(status));
                return this.httpPut('/api/address-details/' + this.addressId + '/update-status', {
                        status: status
                    })
                    .then(data => {
                        this.addressData.customer_status = data.customer_status;
                        alertify.notify('Status has been updated.', 'success', 3);
                        this.setAddressMouseLeaveListener();

                        this.loadAddressDetails();

                        setTimeout(()=>{
                            this.setAddressMouseOverListener([this.addressData]);
                        },500);

                        this.reloadAddressFeeds();

                        return data;
                    })
                    .catch(err => {
                        alertify.notify('Error occured', 'error', 3);
                    })
            },
            showSlidedBox: function (componentToDisplay) {
                if(this.sideComponentToDisplay == componentToDisplay) {
                    this.isExpanded = !this.isExpanded;
                    componentToDisplay = '';
                } else {
                    this.isExpanded = true;
                }
                this.sideComponentToDisplay = componentToDisplay;
                this.$root.logData('detail', 'show slided box', JSON.stringify(componentToDisplay));

                if(this.isExpanded){
                    this.addMainSidebarExpandedClass();
                }
                else {
                    this.removeMainSidebarExpandedClass();
                }
            },
            showContactsChain: function (addressData) {
                this.$eventGlobal.$emit('showModalContactsChain', addressData)
                this.$root.logData('detail', 'show modal contacts chain', JSON.stringify(addressData));
            },
            showOnMap: function () {

                if(localStorage.getItem('map-component') === 'map-main-large') {
                    this.$eventGlobal.$emit('navigateToPoint', [this.addressData.lon, this.addressData.lat]);
                }
                else {
                    this.$eventGlobal.$emit('showSpecificItem', [this.addressData]);
                }

                this.$root.logData('detail', 'show specific item', JSON.stringify(this.addressData));
            },
            showModalIfPersonHashDetected: function () {
                if(this.$route.hash.indexOf('#person-') !== -1) {
                    let personId = this.$route.hash.replace('#person-', '');
                    this.showEmployeeDetailsModal(personId, this.addressId, this.addressData);
                }
            },

            showModalIfProductsAll: function () {
                this.$eventGlobal.$emit('showAllProductItem', [this.addressData])
            },
            loadAllTags: function () {
                this.httpGet('/api/address-details/' + this.addressId + '/get-all-tags')
                    .then(data => {
                        this.allTags = data;
                    })
            },
            loadSelectedTags: function () {
                return this.httpGet('/api/address-details/' + this.addressId + '/load-selected-tags')
                    .then(data => {
                        this.old.tags = data;
                        return data;
                    })
            },
            toggleEditing: function () {
                this.isEditing = !this.isEditing;
                this.$root.logData('detail', 'toggle edit address', JSON.stringify(this.isEditing));
                if ( ! this.isEditing) {
                    this.addressData.name = this.old.name;
                    this.addressData.address = this.old.address;
                    this.addressData.url = this.old.url;
                    this.addressData.phone = this.old.phone;
                    this.addressData.tags = this.old.tags;
                } else {
                    this.loadSelectedTags();
                    this.checkIfChangesMade();
                }
            },
            toggleEditingInput: function (input) {
                this.editingInput = input;
                this.$root.logData('detail', 'toggle editing input', JSON.stringify(input));
            },
            updateAddress: function () {
                if (this.madeChanges && ! this.saveBtnDisabled) {
                    this.$root.logData('detail', 'update address', JSON.stringify({
                        name: this.addressData.name,
                        address: this.addressData.address,
                        url: this.addressData.url,
                        phone: this.addressData.phone,
                        tags: this.addressData.tags
                    }));
                    return this.httpPut('/api/address-details/' + this.addressData.id + '/update-details', {
                        name: this.addressData.name,
                        address: this.addressData.address,
                        url: this.addressData.url,
                        phone: this.addressData.phone,
                        tags: this.addressData.tags
                    })
                    .then(data => {
                        this.old.name = this.addressData.name;
                        this.old.address = this.addressData.address;
                        this.old.url = this.addressData.url;
                        this.old.phone = this.addressData.phone;
                        this.loadSelectedTags();
                        this.madeChanges = false;
                        this.saveBtnDisabled = false;
                        this.editingInput = null;
                        this.isEditing = false;
                        alertify.notify('Address has been updated.', 'success', 3);

                        return data;
                    })
                    .catch(err => {
                        alertify.notify('Error occured', 'error', 3);
                    })
                }  
            },
            removeSelectedTag: function (name) {
                let tags = this.addressData.tags.filter(function(item) {
                    return item.name != name;
                });

                this.addressData.tags = tags;
                this.$root.logData('detail', 'remove selected tag', JSON.stringify(name));
            },
            closeChain: function () {
                this.chainSelect = false;
                this.$root.logData('detail', 'close chain', JSON.stringify(this.chainSelect));
            },
            toggleChain: function () {
                this.chainSelect = !this.chainSelect
                this.$root.logData('detail', 'toggle chain select', JSON.stringify(this.chainSelect));
            },
            closeProducts: function () {
                this.isProductsEditing = false;
                this.$root.logData('detail', 'close edit products', JSON.stringify(this.isProductsEditing));
            },
            toggleProducts: function () {
                this.isProductsEditing = !this.isProductsEditing;
                this.$root.logData('detail', 'toggle edit products', JSON.stringify(this.isProductsEditing));
            },
            updateProducts: function (selectedProducts) {
                this.$root.logData('detail', 'update products', JSON.stringify(selectedProducts));
                return this.httpPost('/api/products/' + this.addressData.id + '/update', {
                        selectedProducts: selectedProducts
                    })
                    .then(data => {
                        this.addressData.products = [];
                        this.addressData.products = data.products;
                        alertify.notify('Used products has been updated.', 'success', 3);
                        this.closeProducts();
                        this.$eventGlobal.$emit('addressProductsUpdated')

                        return data;
                    })
                    .catch(err => {
                        alertify.notify('Error occured', 'error', 3);
                    })
            },
            addNewProduct: function (newItem) {
                this.$root.logData('detail', 'add new product', JSON.stringify(newItem));
                let formData = new FormData();
                formData.append('company', newItem.company);
                formData.append('name', newItem.name);
                formData.append('description', newItem.description);
                formData.append('image', newItem.image);
                return this.httpPost('/api/products/create/' + this.addressId,
                    formData
                )
                .then(data => {
                    if (data.status) {
                        alertify.notify(data.message, 'error', 3);
                        return data;
                    }
                    this.addressData.products = [];
                    this.addressData.products = data.address.products;
                    this.closeProducts();
                    alertify.notify('New product has been added.', 'success', 3);

                    return data;
                })
                .catch(error => {
                    console.log(error);
                    alertify.notify('Error occured', 'error', 3);
                });
            },
            toggleShowAllProducts: function () {
                this.showAllProducts = !this.showAllProducts;
                this.$root.logData('detail', 'toggle showAll products', JSON.stringify(this.showAllProducts));
            },
            productName: function (company, name) {
                return name ? company + ': ' + name : company;
            },
            onCloseSlidedBox: function () {
                this.isExpanded = false;
                this.sideComponentToDisplay = '';
                this.$root.logData('detail', 'close slided box', JSON.stringify(''));
                this.removeMainSidebarExpandedClass();
            },

            mapAddressPropertiesToForm: function () {
                this.old.name = this.addressData.name;
                this.old.address = this.addressData.address;
                this.old.url = this.addressData.url;
                this.old.phone = this.addressData.phone;
                this.madeChanges = false;
                this.saveBtnDisabled = true;
            },
            createNewChain: _.debounce(function () {
                this.$root.logData('detail', 'create new chain', JSON.stringify(this.addressData.name));
                this.httpPost('/api/clusters/create/' + this.addressData.id,
                    {
                        name: this.addressData.name
                    }
                )
                .then(data => {
                    if (data.status && data.status === 'error') {
                        alertify.notify(data.message , 'error', 3);
                    }

                    if (data.status && data.status === 'success') {
                        this.addressData.cluster = data.cluster;
                        this.addressData.cluster_id = data.cluster.id;
                        alertify.notify('New labchain has been added.', 'success', 3);
                        this.chainSelect = false;
                        this.$eventGlobal.$emit('addressClusterUpdated');
                    }
                })
                .catch(error => {
                    console.log(error);
                    alertify.notify('Error occured', 'error', 3);
                });
            }, 400),

            listenWhenMapLoadedAndReady: function () {
                this.$eventGlobal.$on('onMapLoadedAndReady', ()=>{
                    this.setAddressMouseOverListener([this.addressData]);
                });
            },

            navigateToRelationshipGraphPage: function () {

                if(!this.addressData.has_relationships_for_graph) {
                    return;
                }

                let url = '/'+this.$route.params['country']+'/address-relationship-graph/'+this.addressId;

                if($('#tip-3').length) {
                    url += '?1&included-addresses%5B%5D=3135';
                }

                this.$router.push(url);
            },

            showDislikeConfirmation: function () {

                let data = {
                    id: this.addressData.id,
                    reason: 2,
                };

                this.httpPost('/api/set-address-ignore', data)
                    .then(data => {
                        this.$eventGlobal.$emit('dislikeSet', {
                            entityData: this.entityData,
                            shouldReload: true
                        });

                        this.reloadAddressFeeds();
                    })
                    .catch(error => {
                        if(error.data.hasOwnProperty('message')) {
                            alertify.notify(error.data.message, 'error', 3)
                        }
                    })
            },

            subscribeToEvents: function () {
                this.$on('all-products-view', function (componentToDisplay) {

                    this.showSlidedBox(componentToDisplay);

                });

                this.$eventGlobal.$on('dislikeSet', data => {
                    if(data.shouldReload) {
                        this.loadAddressDetails();
                        this.reloadAddressFeeds();
                    }
                });

                this.listenWhenMapLoadedAndReady();
            },

            removeAddressFromIgnore: function () {
                this.removeEntityFromIgnoreList('/api/remove-address-from-ignore-list', {id: this.addressData.id})
                    .then(data => {
                        this.loadAddressDetails();

                        this.reloadAddressFeeds();
                    })
            },

            reloadAddressFeeds: function () {
                if(this.$refs.quickFeedBoxRef) {
                    this.$refs.quickFeedBoxRef.loadAddressesFeeds();
                }
            }
        },
        computed: {
            showHideProducts: function () {
                return this.showAllProducts ? 'Show less' : 'Show all';
            }
        },

        mounted: function () {

            if(this.user.default_country === 'usa-private') {
                return;
            }

            this.subscribeToEvents();

            this.addressId = this.$route.params.id;

            this.loadAllTags();

            this.loadAddressDetails()
                .then(() => {
                    this.showModalIfPersonHashDetected(this.addressId, this.addressData);
                    this.mapAddressPropertiesToForm();
                    this.isFirstLoad = false;
                });

            this.loadCustomerStatusList();

            if(this.$route.query['all-employees']) {
                setTimeout(() => {
                    this.showSlidedBox('all-employee');
                }, 0)
            }

            this.$root.logData('detail', 'open', JSON.stringify(''));
        },
        beforeDestroy: function () {
            this.$eventGlobal.$off('onMapLoadedAndReady');
            this.$eventGlobal.$off('dislikeSet');
            this.setAddressMouseLeaveListener();
        }
    }
</script>